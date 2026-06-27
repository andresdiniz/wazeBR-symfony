<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\WazeRoute;
use App\Entity\WazeRouteSnapshot;
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
 * Coleta dados atuais (tempo, congestionamento) das rotas Waze ativas
 * e persiste um WazeRouteSnapshot para histórico.
 *
 * Dois modos de coleta:
 *
 * 1. Routing API — rota tem `coordinates` (from/to):
 *    https://www.waze.com/row-RoutingManager/routingRequest
 *
 * 2. TVT API — rota tem `wazeId` mas NÃO tem `coordinates`:
 *    https://www.waze.com/row-partnerhub-api/feeds-tvt/{uuid}?id={wazeId}
 *    A URL base do feed deve estar em Partner::feedUrl.
 */
#[AsCommand(
    name: 'app:waze:collect-routes',
    description: 'Coleta tempos e congestionamento das rotas Waze ativas e salva snapshots.',
)]
class WazeCollectRoutesCommand extends Command
{
    private const ROUTING_URL = 'https://www.waze.com/row-RoutingManager/routingRequest';

    public function __construct(
        private readonly WazeRouteRepository   $routeRepo,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface    $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Busca e exibe os dados sem persistir nada')
            ->addOption('partner', null, InputOption::VALUE_REQUIRED,
                'Filtrar por slug do parceiro (ex: prefeitura-bh)');
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

        /** @var WazeRoute[] $routes */
        $routes = $partnerSlug
            ? $this->routeRepo->findActiveByPartnerSlug($partnerSlug)
            : $this->routeRepo->findAllActive();

        if (empty($routes)) {
            $io->warning('Nenhuma rota ativa encontrada.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('  %d rota(s) ativa(s) encontrada(s).', count($routes)));
        $io->newLine();

        $ok     = 0;
        $errors = 0;

        foreach ($routes as $route) {
            $label   = $route->getName() ?? $route->getWazeId() ?? "Rota #{$route->getId()}";
            $partner = $route->getPartner()?->getName() ?? '—';

            $io->section("{$partner} — {$label}");

            // ── Modo TVT: tem wazeId mas não tem coordinates ───────────────────
            if ($route->getWazeId() !== null && empty($route->getCoordinates())) {
                [$ok, $errors] = $this->collectViaTvt($route, $io, $dryRun, $ok, $errors);
                continue;
            }

            // ── Modo Routing API: tem coordinates ─────────────────────────────
            $coords = $this->resolveCoordinates($route);
            if ($coords === null) {
                $io->warning('Sem coordenadas (from/to) nem wazeId configurados. Pulando.');
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
            $line         = $routeData['line'] ?? null;

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

            if ($line !== null) {
                $route->setLine($line);
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

        $io->newLine();
        if ($errors > 0) {
            $io->warning("Concluído com erros — OK: {$ok} | Erros: {$errors}");
        } else {
            $io->success("Concluído — {$ok} rota(s) atualizada(s).");
        }

        return Command::SUCCESS;
    }

    // ── Modo TVT ──────────────────────────────────────────────────────────────

    /**
     * Coleta dados via feeds-tvt quando a rota tem wazeId mas não coordinates.
     * O Partner deve ter feedUrl preenchido com a URL base do feed:
     *   ex: https://www.waze.com/row-partnerhub-api/feeds-tvt/9bb3e551-...
     *
     * @return array{int, int} [$ok, $errors]
     */
    private function collectViaTvt(
        WazeRoute $route,
        SymfonyStyle $io,
        bool $dryRun,
        int $ok,
        int $errors
    ): array {
        $partner = $route->getPartner();
        $feedUrl = $partner?->getFeedUrl();

        if (empty($feedUrl)) {
            $io->warning(sprintf(
                'Rota TVT (wazeId=%s) sem feedUrl no parceiro "%s". Pulando.',
                $route->getWazeId(),
                $partner?->getName() ?? '—',
            ));
            return [$ok, ++$errors];
        }

        $url = rtrim($feedUrl, '?&') . '?id=' . $route->getWazeId();

        try {
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
                throw new \RuntimeException("HTTP {$status}");
            }

            $data = $response->toArray();
        } catch (\Throwable $e) {
            $io->error('Erro na API TVT: ' . $e->getMessage());
            return [$ok, ++$errors];
        }

        // Encontra a rota pelo wazeId dentro do array routes[]
        $matched = null;
        foreach ($data['routes'] ?? [] as $r) {
            if (isset($r['id']) && (string) $r['id'] === (string) $route->getWazeId()) {
                $matched = $r;
                break;
            }
        }

        if ($matched === null) {
            // Fallback: usa a primeira rota se o feed retornar apenas uma
            $matched = $data['routes'][0] ?? null;
        }

        if ($matched === null) {
            $io->warning(sprintf('wazeId=%s não encontrado no feed TVT.', $route->getWazeId()));
            return [$ok, ++$errors];
        }

        $time         = isset($matched['time'])         ? (int) $matched['time']         : null;
        $historicTime = isset($matched['historicTime']) ? (int) $matched['historicTime'] : null;
        $length       = isset($matched['length'])       ? (int) $matched['length']       : null;
        $jamLevel     = isset($matched['jamLevel'])     ? (int) $matched['jamLevel']     : null;
        $line         = $matched['line'] ?? null;
        $bbox         = $matched['bbox'] ?? null;

        if ($dryRun) {
            $io->writeln(sprintf(
                '  DRY-RUN [TVT]: wazeId=%s | time=%ss | historicTime=%ss | length=%sm | jamLevel=%s',
                $route->getWazeId(), $time ?? '?', $historicTime ?? '?', $length ?? '?', $jamLevel ?? '?',
            ));
            return [++$ok, $errors];
        }

        $route
            ->setTime($time)
            ->setHistoricTime($historicTime)
            ->setLength($length)
            ->setJamLevel($jamLevel)
            ->setCollectedAt(new \DateTime());

        if ($line !== null) {
            $route->setLine($line);
        }
        if ($bbox !== null) {
            $route->setBbox($bbox);
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
            '  ✓ [TVT] wazeId=<info>%s</info> | time=<info>%ds</info> | historic=<info>%ds</info> | length=<info>%dm</info> | jam=<info>%s</info>%s',
            $route->getWazeId(),
            $time ?? 0, $historicTime ?? 0, $length ?? 0, $jamLevel ?? '?',
            $delay !== null ? " | atraso=<comment>{$delay}min</comment>" : '',
        ));

        return [++$ok, $errors];
    }

    // ── HTTP Routing ──────────────────────────────────────────────────────────

    private function fetchRoute(array $from, array $to): array
    {
        $response = $this->httpClient->request('GET', self::ROUTING_URL, [
            'timeout' => 30,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'WazeBR-Symfony/1.0',
                'Referer'    => 'https://www.waze.com/',
            ],
            'query' => [
                'from'               => "x:{$from['x']},y:{$from['y']}",
                'to'                 => "x:{$to['x']},y:{$to['y']}",
                'returnJSON'         => 'true',
                'returnGeometries'   => 'true',
                'returnInstructions' => 'false',
                'timeout'            => '60000',
                'nPaths'             => '3',
                'options'            => 'AVOID_TRAILS:t',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("HTTP {$status}");
        }

        return $response->toArray();
    }

    // ── Coordenadas ───────────────────────────────────────────────────────────

    /**
     * @return array{from: array, to: array}|null
     */
    private function resolveCoordinates(WazeRoute $route): ?array
    {
        $raw = $route->getCoordinates();

        if (empty($raw)) {
            return null;
        }

        $entry = isset($raw[0]) && is_array($raw[0]) ? $raw[0] : $raw;

        $from = $entry['from'] ?? null;
        $to   = $entry['to']   ?? null;

        if (!isset($from['x'], $from['y'], $to['x'], $to['y'])) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }
}
