<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitoredLink;
use App\Entity\WazeRoute;
use App\Entity\WazeRouteSnapshot;
use App\Entity\WazeSubRoute;
use App\Repository\MonitoredLinkRepository;
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
 * Modo 1 — TVT (MonitoredLink link_type=waze_tvt):
 *   Itera os MonitoredLink com link_type=waze_tvt, chama o feed TVT,
 *   cria/atualiza WazeRoute pelo wazeId+partner (upsert) e salva snapshot.
 *   As subRoutes de cada rota são salvas em waze_sub_routes (WazeSubRoute).
 *
 * Modo 2 — Routing API (WazeRoute com coordinates):
 *   Itera WazeRoute ativas com coordinates preenchido e consulta
 *   a API de roteamento do Waze.
 */
#[AsCommand(
    name: 'app:waze:collect-routes',
    description: 'Coleta tempos e congestionamento das rotas Waze ativas e salva snapshots.',
)]
class WazeCollectRoutesCommand extends Command
{
    private const ROUTING_URL = 'https://www.waze.com/row-RoutingManager/routingRequest';

    public function __construct(
        private readonly WazeRouteRepository    $routeRepo,
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly EntityManagerInterface  $em,
        private readonly HttpClientInterface     $httpClient,
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

        // ── MODO TVT: itera MonitoredLink waze_tvt ──────────────────────────
        /** @var MonitoredLink[] $tvtLinks */
        $tvtLinks = $this->linkRepo->findActiveWazeTvtLinks();

        if (!empty($tvtLinks)) {
            $io->section('Modo TVT — feeds-tvt');

            foreach ($tvtLinks as $link) {
                $partnerName = $link->getPartner()?->getName() ?? '—';
                $partnerObj  = $link->getPartner();

                // Filtro por parceiro se passado via opção
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

                $rawRoutes = $data['routes'] ?? [];

                if (empty($rawRoutes)) {
                    $io->warning('Nenhuma rota no feed TVT.');
                    continue;
                }

                foreach ($rawRoutes as $rawRoute) {
                    $wazeId = isset($rawRoute['id']) ? (string) $rawRoute['id'] : null;
                    $name   = $rawRoute['name'] ?? null;

                    if ($dryRun) {
                        $io->writeln(sprintf(
                            '  DRY-RUN: id=%s | name=%s | time=%s | historicTime=%s | length=%s | jamLevel=%s | subRoutes=%d',
                            $wazeId ?? '?',
                            $name   ?? '?',
                            $rawRoute['time']         ?? '?',
                            $rawRoute['historicTime']  ?? '?',
                            $rawRoute['length']        ?? '?',
                            $rawRoute['jamLevel']      ?? '?',
                            count($rawRoute['subRoutes'] ?? []),
                        ));
                        $ok++;
                        continue;
                    }

                    // Upsert: busca pelo wazeId + partner, cria se não existir
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

                    $time         = isset($rawRoute['time'])         ? (int) $rawRoute['time']         : null;
                    $historicTime = isset($rawRoute['historicTime']) ? (int) $rawRoute['historicTime'] : null;
                    $length       = isset($rawRoute['length'])       ? (int) $rawRoute['length']       : null;
                    $jamLevel     = isset($rawRoute['jamLevel'])     ? (int) $rawRoute['jamLevel']     : null;

                    $route
                        ->setName($rawRoute['name']       ?? $route->getName())
                        ->setFromName($rawRoute['fromName'] ?? $route->getFromName())
                        ->setToName($rawRoute['toName']   ?? $route->getToName())
                        ->setType($rawRoute['type']       ?? $route->getType())
                        ->setTime($time)
                        ->setHistoricTime($historicTime)
                        ->setLength($length)
                        ->setJamLevel($jamLevel)
                        ->setCollectedAt(new \DateTime());

                    if (!empty($rawRoute['line'])) {
                        $route->setLine($rawRoute['line']);
                    }
                    if (!empty($rawRoute['bbox'])) {
                        $route->setBbox($rawRoute['bbox']);
                    }

                    // ── Sincroniza subRoutes em waze_sub_routes ──────────────
                    // Remove todas as sub-rotas anteriores (orphanRemoval cuida do DELETE)
                    foreach ($route->getSubRoutes() as $old) {
                        $route->removeSubRoute($old);
                    }

                    foreach (($rawRoute['subRoutes'] ?? []) as $sortOrder => $rawSub) {
                        $sub = (new WazeSubRoute())
                            ->setFromName($rawSub['fromName'] ?? null)
                            ->setToName($rawSub['toName']     ?? null)
                            ->setTime(isset($rawSub['time'])               ? (int) $rawSub['time']         : null)
                            ->setHistoricTime(isset($rawSub['historicTime']) ? (int) $rawSub['historicTime'] : null)
                            ->setLength(isset($rawSub['length'])           ? (int) $rawSub['length']       : null)
                            ->setJamLevel(isset($rawSub['jamLevel'])       ? (int) $rawSub['jamLevel']     : null)
                            ->setLine($rawSub['line'] ?? null)
                            ->setBbox($rawSub['bbox'] ?? null)
                            ->setSortOrder($sortOrder);

                        $route->addSubRoute($sub);
                    }
                    // ────────────────────────────────────────────────────────

                    $this->em->persist($route);
                    $this->em->flush(); // flush para garantir que $route->getId() esteja preenchido

                    $snapshot = (new WazeRouteSnapshot())
                        ->setRoute($route)
                        ->setTime($time)
                        ->setHistoricTime($historicTime)
                        ->setLength($length)
                        ->setJamLevel($jamLevel);

                    $this->em->persist($snapshot);

                    $delay = ($time !== null && $historicTime !== null && $historicTime > 0)
                        ? round(($time - $historicTime) / 60, 1)
                        : null;

                    $io->writeln(sprintf(
                        '  ✓ [TVT] id=<info>%s</info> | %s | time=<info>%ds</info> | historic=<info>%ds</info> | jam=<info>%s</info> | sub=<info>%d</info>%s',
                        $wazeId ?? '?',
                        $name   ?? '(sem nome)',
                        $time ?? 0, $historicTime ?? 0, $jamLevel ?? '?',
                        count($rawRoute['subRoutes'] ?? []),
                        $delay !== null ? " | atraso=<comment>{$delay}min</comment>" : '',
                    ));

                    $ok++;
                }

                if (!$dryRun) {
                    $link->setLastCollectedAt(new \DateTimeImmutable());
                    $this->em->flush();
                }
            }
        }

        // ── MODO ROUTING API: WazeRoute com coordinates ────────────────────
        $routingRoutes = $partnerSlug
            ? $this->routeRepo->findActiveByPartnerSlug($partnerSlug)
            : $this->routeRepo->findAllActive();

        // Filtra apenas as que têm coordinates (modo Routing)
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
                    $io->warning('Coordenadas inválidas (from/to ausentes). Pulando.');
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
                    $io->warning('Resposta inesperada da API (sem response). Pulando.');
                    $errors++;
                    continue;
                }

                $time         = isset($routeData['totalRouteTime'])  ? (int) $routeData['totalRouteTime']  : null;
                $historicTime = isset($routeData['totalRoutTime'])    ? (int) $routeData['totalRoutTime']    : null;
                $length       = isset($routeData['totalRouteLength']) ? (int) $routeData['totalRouteLength'] : null;
                $jamLevel     = isset($routeData['jamLevel'])         ? (int) $routeData['jamLevel']         : null;

                if ($dryRun) {
                    $io->writeln(sprintf(
                        '  DRY-RUN [Routing]: time=%ss | historicTime=%ss | length=%sm | jamLevel=%s',
                        $time ?? '?', $historicTime ?? '?', $length ?? '?', $jamLevel ?? '?',
                    ));
                    $ok++;
                    continue;
                }

                $route
                    ->setTime($time)
                    ->setHistoricTime($historicTime)
                    ->setLength($length)
                    ->setJamLevel($jamLevel)
                    ->setCollectedAt(new \DateTime());

                if (!empty($routeData['line'])) {
                    $route->setLine($routeData['line']);
                }

                $snapshot = (new WazeRouteSnapshot())
                    ->setRoute($route)
                    ->setTime($time)
                    ->setHistoricTime($historicTime)
                    ->setLength($length)
                    ->setJamLevel($jamLevel);

                $this->em->persist($snapshot);
                $this->em->flush();

                $delay = ($time !== null && $historicTime !== null && $historicTime > 0)
                    ? round(($time - $historicTime) / 60, 1)
                    : null;

                $io->writeln(sprintf(
                    '  ✓ [Routing] time=<info>%ds</info> | historic=<info>%ds</info> | length=<info>%dm</info> | jam=<info>%s</info>%s',
                    $time ?? 0, $historicTime ?? 0, $length ?? 0, $jamLevel ?? '?',
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

    // ── HTTP helpers ────────────────────────────────────────────────────

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

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("HTTP {$status} ao acessar {$url}");
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
