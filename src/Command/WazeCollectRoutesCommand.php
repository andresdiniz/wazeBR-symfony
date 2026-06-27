<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitoredLink;
use App\Entity\WazeIrregularity;
use App\Entity\WazeRoute;
use App\Entity\WazeRouteSnapshot;
use App\Entity\WazeSubRoute;
use App\Repository\MonitoredLinkRepository;
use App\Repository\WazeIrregularityRepository;
use App\Repository\WazeRouteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta dados atuais das rotas Waze e salva WazeRouteSnapshot.
 *
 * Modo TVT (MonitoredLink link_type=waze_tvt):
 *   - Itera os MonitoredLink waze_tvt ativos
 *   - Upsert WazeRoute por (wazeId + partner)
 *   - Salva WazeRouteSnapshot (histórico imutável)
 *   - Sincroniza WazeSubRoute com campos leadAlert e avgSpeed
 *   - Upsert WazeIrregularity por (wazeId + sourceLink); desativa as ausentes
 *
 * Modo Routing API (WazeRoute com coordinates):
 *   - Consulta a API de roteamento do Waze
 */
#[AsCommand(
    name: 'app:waze:collect-routes',
    description: 'Coleta tempos e congestionamento das rotas Waze ativas e salva snapshots.',
)]
class WazeCollectRoutesCommand extends Command
{
    private const ROUTING_URL = 'https://www.waze.com/row-RoutingManager/routingRequest';

    public function __construct(
        private readonly WazeRouteRepository        $routeRepo,
        private readonly MonitoredLinkRepository    $linkRepo,
        private readonly WazeIrregularityRepository $irregularityRepo,
        private readonly EntityManagerInterface     $em,
        private readonly HttpClientInterface        $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Busca e exibe os dados sem persistir nada')
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'Filtrar por slug do parceiro');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $dryRun      = (bool) $input->getOption('dry-run');
        $partnerSlug = $input->getOption('partner');

        $io->title('Waze Collect Routes');
        if ($dryRun) {
            $io->note('Modo DRY-RUN: nenhum dado será persistido.');
        }

        $ok     = 0;
        $errors = 0;

        // ── MODO TVT ────────────────────────────────────────────────────────
        /** @var MonitoredLink[] $tvtLinks */
        $tvtLinks = $this->linkRepo->findActiveWazeTvtLinks();

        if (!empty($tvtLinks)) {
            $io->section('Modo TVT — feeds-tvt');

            foreach ($tvtLinks as $link) {
                $partnerObj  = $link->getPartner();
                $partnerName = $partnerObj?->getName() ?? '—';

                if ($partnerSlug && $partnerObj?->getSlug() !== $partnerSlug) {
                    continue;
                }

                $io->writeln(sprintf('<info>Parceiro:</info> %s — %s', $partnerName, $link->getUrl()));

                try {
                    $data = $this->fetchJson($link->getUrl());
                } catch (\Throwable $e) {
                    $io->error('Erro ao buscar feed TVT: ' . $e->getMessage());
                    $errors++;
                    continue;
                }

                // ── 1. usersOnJams ────────────────────────────────────────
                // Já persistido pelo WazeCollectFeedCommand / WazeCount —
                // aqui apenas logamos para auditoria sem duplicar.
                if (!empty($data['usersOnJams'])) {
                    $io->writeln(sprintf(
                        '  usersOnJams: %d níveis encontrados',
                        count($data['usersOnJams'])
                    ));
                }

                // ── 2. Rotas ──────────────────────────────────────────────
                $rawRoutes = $data['routes'] ?? [];

                if (empty($rawRoutes)) {
                    $io->warning('Nenhuma rota no feed TVT.');
                } else {
                    foreach ($rawRoutes as $rawRoute) {
                        $wazeId = isset($rawRoute['id']) ? (string) $rawRoute['id'] : null;
                        $name   = $rawRoute['name'] ?? null;

                        // Calcular velocidades
                        $length      = max(1, (int) ($rawRoute['length']      ?? 1));
                        $time        = max(1, (int) ($rawRoute['time']        ?? 1));
                        $historicT   = max(1, (int) ($rawRoute['historicTime'] ?? 1));
                        $avgSpeed     = round(($length / 1000) / ($time      / 3600), 2);
                        $historicSpeed= round(($length / 1000) / ($historicT / 3600), 2);

                        if ($dryRun) {
                            $io->writeln(sprintf(
                                '  DRY-RUN: id=%s | %s | time=%s | historicTime=%s | jam=%s | avgSpeed=%s km/h | sub=%d | irr=%d',
                                $wazeId ?? '?', $name ?? '?',
                                $rawRoute['time'] ?? '?', $rawRoute['historicTime'] ?? '?',
                                $rawRoute['jamLevel'] ?? '?', $avgSpeed,
                                count($rawRoute['subRoutes'] ?? []),
                                count($data['irregularities'] ?? []),
                            ));
                            $ok++;
                            continue;
                        }

                        // Upsert WazeRoute
                        $route = $wazeId && $partnerObj
                            ? $this->em->getRepository(WazeRoute::class)->findOneBy([
                                'wazeId'  => $wazeId,
                                'partner' => $partnerObj,
                            ])
                            : null;

                        if ($route === null) {
                            $route = (new WazeRoute())
                                ->setPartner($partnerObj)
                                ->setWazeId($wazeId)
                                ->setIsActive(true);
                        }

                        $rawTime = isset($rawRoute['time'])         ? (int) $rawRoute['time']         : null;
                        $rawHist = isset($rawRoute['historicTime']) ? (int) $rawRoute['historicTime'] : null;
                        $rawLen  = isset($rawRoute['length'])       ? (int) $rawRoute['length']       : null;
                        $rawJam  = isset($rawRoute['jamLevel'])     ? (int) $rawRoute['jamLevel']     : null;

                        $route
                            ->setName($rawRoute['name']     ?? $route->getName())
                            ->setFromName($rawRoute['fromName'] ?? $route->getFromName())
                            ->setToName($rawRoute['toName']   ?? $route->getToName())
                            ->setType($rawRoute['type']       ?? $route->getType())
                            ->setTime($rawTime)
                            ->setHistoricTime($rawHist)
                            ->setLength($rawLen)
                            ->setJamLevel($rawJam)
                            ->setCollectedAt(new \DateTime());

                        if (!empty($rawRoute['line'])) {
                            $route->setLine($rawRoute['line']);
                        }
                        if (!empty($rawRoute['bbox'])) {
                            $route->setBbox($rawRoute['bbox']);
                        }

                        // ── Sincroniza subRoutes (orphanRemoval) ──────────
                        foreach ($route->getSubRoutes() as $old) {
                            $route->removeSubRoute($old);
                        }

                        foreach (($rawRoute['subRoutes'] ?? []) as $sortOrder => $rawSub) {
                            $subLen  = max(1, (int) ($rawSub['length']      ?? 1));
                            $subTime = max(1, (int) ($rawSub['time']        ?? 1));
                            $subHist = max(1, (int) ($rawSub['historicTime'] ?? 1));

                            $leadAlert = $rawSub['leadAlert'] ?? null;

                            $sub = (new WazeSubRoute())
                                ->setFromName($rawSub['fromName'] ?? null)
                                ->setToName($rawSub['toName']    ?? null)
                                ->setTime($subTime)
                                ->setHistoricTime($subHist)
                                ->setLength($subLen)
                                ->setJamLevel(isset($rawSub['jamLevel']) ? (int) $rawSub['jamLevel'] : null)
                                ->setAvgSpeed(round(($subLen / 1000) / ($subTime / 3600), 2))
                                ->setHistoricSpeed(round(($subLen / 1000) / ($subHist / 3600), 2))
                                ->setLine($rawSub['line'] ?? null)
                                ->setBbox($rawSub['bbox'] ?? null)
                                ->setSortOrder($sortOrder)
                                // lead alert
                                ->setLeadAlertId($leadAlert['id']               ?? null)
                                ->setLeadAlertType($leadAlert['type']            ?? null)
                                ->setLeadAlertSubType($leadAlert['subType']      ?? null)
                                ->setLeadAlertPosition(isset($leadAlert['position']) ? (array) $leadAlert['position'] : null)
                                ->setLeadAlertNumComments($leadAlert['numComments']          ?? null)
                                ->setLeadAlertNumThumbsUp($leadAlert['numThumbsUp']          ?? null)
                                ->setLeadAlertNumNotThereReports($leadAlert['numNotThereReports'] ?? null)
                                ->setLeadAlertStreet($leadAlert['street']        ?? null);

                            $route->addSubRoute($sub);
                        }

                        $this->em->persist($route);
                        $this->em->flush();

                        // WazeRouteSnapshot (histórico imutável)
                        $snapshot = (new WazeRouteSnapshot())
                            ->setRoute($route)
                            ->setTime($rawTime)
                            ->setHistoricTime($rawHist)
                            ->setLength($rawLen)
                            ->setJamLevel($rawJam);

                        $this->em->persist($snapshot);

                        $delay = ($rawTime !== null && $rawHist !== null && $rawHist > 0)
                            ? round(($rawTime - $rawHist) / 60, 1)
                            : null;

                        $io->writeln(sprintf(
                            '  ✓ [TVT] id=<info>%s</info> | %s | time=<info>%ds</info> | historic=<info>%ds</info> | jam=<info>%s</info> | speed=<info>%s km/h</info> | sub=<info>%d</info>%s',
                            $wazeId ?? '?', $name ?? '(sem nome)',
                            $rawTime ?? 0, $rawHist ?? 0, $rawJam ?? '?',
                            $avgSpeed,
                            count($rawRoute['subRoutes'] ?? []),
                            $delay !== null ? " | atraso=<comment>{$delay}min</comment>" : '',
                        ));

                        $ok++;
                    }

                    $this->em->flush();
                }

                // ── 3. Irregularidades ────────────────────────────────────
                $rawIrregularities = $data['irregularities'] ?? [];

                if (!$dryRun) {
                    // Desativa tudo que veio antes para este link
                    $existing = $this->irregularityRepo->findActiveByLink($link);
                    foreach ($existing as $irr) {
                        $irr->setIsActive(false);
                    }
                    $this->em->flush();

                    foreach ($rawIrregularities as $rawIrr) {
                        $irrWazeId = isset($rawIrr['id']) ? (string) $rawIrr['id'] : null;

                        // Upsert por (wazeId + sourceLink)
                        $irr = $irrWazeId
                            ? $this->em->getRepository(WazeIrregularity::class)->findOneBy([
                                'wazeId'     => $irrWazeId,
                                'sourceLink' => $link,
                            ])
                            : null;

                        if ($irr === null) {
                            $irr = (new WazeIrregularity())
                                ->setWazeId($irrWazeId)
                                ->setPartner($partnerObj)
                                ->setSourceLink($link);
                        }

                        $irrLen  = max(1, (int) ($rawIrr['length']      ?? 1));
                        $irrTime = max(1, (int) ($rawIrr['time']        ?? 1));
                        $irrHist = max(1, (int) ($rawIrr['historicTime'] ?? 1));
                        $leadAlert = $rawIrr['leadAlert'] ?? null;

                        $irr
                            ->setName($rawIrr['name']         ?? null)
                            ->setFromName($rawIrr['fromName'] ?? null)
                            ->setToName($rawIrr['toName']     ?? null)
                            ->setLength($irrLen)
                            ->setTime($irrTime)
                            ->setHistoricTime($irrHist)
                            ->setJamLevel(isset($rawIrr['jamLevel']) ? (int) $rawIrr['jamLevel'] : null)
                            ->setAvgSpeed(round(($irrLen / 1000) / ($irrTime / 3600), 2))
                            ->setHistoricSpeed(round(($irrLen / 1000) / ($irrHist / 3600), 2))
                            ->setBbox($rawIrr['bbox'] ?? null)
                            ->setLine($rawIrr['line'] ?? null)
                            ->setIsActive(true)
                            ->setCollectedAt(new \DateTimeImmutable())
                            // lead alert
                            ->setLeadAlertId($leadAlert['id']            ?? null)
                            ->setLeadAlertType($leadAlert['type']         ?? null)
                            ->setLeadAlertSubType($leadAlert['subType']   ?? null)
                            ->setLeadAlertPosition(isset($leadAlert['position']) ? (array) $leadAlert['position'] : null)
                            ->setLeadAlertNumComments($leadAlert['numComments']        ?? null)
                            ->setLeadAlertCity($leadAlert['city']                      ?? null)
                            ->setLeadAlertExternalImageId($leadAlert['externalImageId'] ?? null)
                            ->setLeadAlertNumThumbsUp($leadAlert['numThumbsUp']         ?? null)
                            ->setLeadAlertStreet($leadAlert['street']                  ?? null)
                            ->setLeadAlertNumNotThereReports($leadAlert['numNotThereReports'] ?? null);

                        $this->em->persist($irr);
                    }

                    $this->em->flush();

                    if (!empty($rawIrregularities)) {
                        $io->writeln(sprintf(
                            '  ✓ [IRR] %d irregularidade(s) sincronizada(s)',
                            count($rawIrregularities)
                        ));
                    }
                }

                if (!$dryRun) {
                    $link->setLastCollectedAt(new \DateTimeImmutable());
                    $this->em->flush();
                }
            }
        }

        // ── MODO ROUTING API ────────────────────────────────────────────────
        $routingRoutes = $partnerSlug
            ? $this->routeRepo->findActiveByPartnerSlug($partnerSlug)
            : $this->routeRepo->findAllActive();

        $routingRoutes = array_filter(
            $routingRoutes,
            fn(WazeRoute $r) => !empty($r->getCoordinates())
        );

        if (!empty($routingRoutes)) {
            $io->section('Modo Routing API — coordinates');

            foreach ($routingRoutes as $route) {
                $label   = $route->getName() ?? "Rota #{$route->getId()}";
                $partner = $route->getPartner()?->getName() ?? '—';

                $io->writeln(sprintf('<info>%s</info> — %s', $partner, $label));

                $coords = $this->resolveCoordinates($route);
                if ($coords === null) {
                    $io->warning('Coordenadas inválidas. Pulando.');
                    $errors++;
                    continue;
                }

                try {
                    $data = $this->fetchRoute($coords['from'], $coords['to']);
                } catch (\Throwable $e) {
                    $io->error('Erro na API Waze Routing: ' . $e->getMessage());
                    $errors++;
                    continue;
                }

                $routeData = $data['alternatives'][0]['response'] ?? $data['response'] ?? null;

                if ($routeData === null) {
                    $io->warning('Resposta inesperada da API. Pulando.');
                    $errors++;
                    continue;
                }

                $rawTime = isset($routeData['totalRouteTime'])  ? (int) $routeData['totalRouteTime']  : null;
                $rawHist = isset($routeData['totalRoutTime'])   ? (int) $routeData['totalRoutTime']   : null;
                $rawLen  = isset($routeData['totalRouteLength'])? (int) $routeData['totalRouteLength']: null;
                $rawJam  = isset($routeData['jamLevel'])        ? (int) $routeData['jamLevel']        : null;

                if ($dryRun) {
                    $io->writeln(sprintf(
                        '  DRY-RUN [Routing]: time=%ss | historicTime=%ss | length=%sm | jam=%s',
                        $rawTime ?? '?', $rawHist ?? '?', $rawLen ?? '?', $rawJam ?? '?',
                    ));
                    $ok++;
                    continue;
                }

                $route
                    ->setTime($rawTime)
                    ->setHistoricTime($rawHist)
                    ->setLength($rawLen)
                    ->setJamLevel($rawJam)
                    ->setCollectedAt(new \DateTime());

                if (!empty($routeData['line'])) {
                    $route->setLine($routeData['line']);
                }

                $snapshot = (new WazeRouteSnapshot())
                    ->setRoute($route)
                    ->setTime($rawTime)
                    ->setHistoricTime($rawHist)
                    ->setLength($rawLen)
                    ->setJamLevel($rawJam);

                $this->em->persist($snapshot);
                $this->em->flush();

                $delay = ($rawTime !== null && $rawHist !== null && $rawHist > 0)
                    ? round(($rawTime - $rawHist) / 60, 1)
                    : null;

                $io->writeln(sprintf(
                    '  ✓ [Routing] time=<info>%ds</info> | historic=<info>%ds</info> | length=<info>%dm</info> | jam=<info>%s</info>%s',
                    $rawTime ?? 0, $rawHist ?? 0, $rawLen ?? 0, $rawJam ?? '?',
                    $delay !== null ? " | atraso=<comment>{$delay}min</comment>" : '',
                ));

                $ok++;
            }
        }

        $io->newLine();
        if ($errors > 0) {
            $io->warning("Concluído com erros — OK: {$ok} | Erros: {$errors}");
        } else {
            $io->success("Concluído — {$ok} rota(s) processada(s).");
        }

        return Command::SUCCESS;
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    private function fetchJson(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'WazeBR-Symfony/1.0',
                'Referer'    => 'https://www.waze.com/',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("HTTP {$response->getStatusCode()} ao acessar {$url}");
        }

        return $response->toArray();
    }

    private function fetchRoute(array $from, array $to): array
    {
        return $this->fetchJson(self::ROUTING_URL . '?' . http_build_query([
            'from'               => "x:{$from['x']},y:{$from['y']}",
            'to'                 => "x:{$to['x']},y:{$to['y']}",
            'returnJSON'         => 'true',
            'returnGeometries'   => 'true',
            'returnInstructions' => 'false',
            'timeout'            => '60000',
            'nPaths'             => '3',
            'options'            => 'AVOID_TRAILS:t',
        ]));
    }

    private function resolveCoordinates(WazeRoute $route): ?array
    {
        $raw = $route->getCoordinates();
        if (empty($raw)) {
            return null;
        }

        $entry = isset($raw[0]) && is_array($raw[0]) ? $raw[0] : $raw;
        $from  = $entry['from'] ?? null;
        $to    = $entry['to']   ?? null;

        if (!isset($from['x'], $from['y'], $to['x'], $to['y'])) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }
}
