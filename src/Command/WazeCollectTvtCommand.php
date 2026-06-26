<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitoredLink;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtSnapshot;
use App\Repository\MonitoredLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta rotas do feed Waze TVT (feedFormat=2).
 *
 * URL esperada no MonitoredLink:
 *   https://www.waze.com/row-partnerhub-api/feeds-tvt/{uuid}?id={monitorId}
 */
#[AsCommand(
    name: 'app:waze:collect-tvt',
    description: 'Coleta snapshots de rotas do feed TVT Waze para todos os links ativos (feedFormat=2).',
)]
class WazeCollectTvtCommand extends Command
{
    public function __construct(
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly EntityManagerInterface  $em,
        private readonly HttpClientInterface     $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Busca e exibe o JSON sem persistir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Waze Collect TVT — feeds-tvt');
        if ($dryRun) {
            $io->note('Modo DRY-RUN: nenhum dado será persistido.');
        }

        /** @var MonitoredLink[] $links */
        $links = $this->linkRepo->findActiveTrafficFeeds();

        if (empty($links)) {
            $io->warning('Nenhum link TVT ativo (feedFormat=2). Cadastre um MonitoredLink.');
            return Command::SUCCESS;
        }

        $totalSnapshots = 0;

        foreach ($links as $link) {
            $partner = $link->getPartner();
            $label   = $link->getLabel() ?? $link->getUrl();

            $io->section("Parceiro: {$partner->getName()} [{$partner->getSlug()}] — {$label}");
            $io->writeln('  URL: ' . $link->getUrl());

            try {
                $data = $this->fetchFeed($link->getUrl());
            } catch (\Throwable $e) {
                $io->error('Erro ao buscar feed TVT: ' . $e->getMessage());
                continue;
            }

            $rawRoutes = $data['routes'] ?? [];

            if ($dryRun) {
                $io->writeln(sprintf(
                    '  DRY-RUN: %d rota(s) encontrada(s).',
                    count($rawRoutes),
                ));
                foreach ($rawRoutes as $i => $r) {
                    $io->writeln(sprintf(
                        '    Rota %d: id=%s | name=%s | jamLevel=%s | subRoutes=%d',
                        $i + 1,
                        $r['id']       ?? '(sem id)',
                        $r['name']     ?? '(sem nome)',
                        $r['jamLevel'] ?? '?',
                        count($r['subRoutes'] ?? []),
                    ));
                }
                $io->writeln('  usersOnJams:');
                foreach (($data['usersOnJams'] ?? []) as $u) {
                    $io->writeln(sprintf(
                        '    jamLevel=%d → %d usuários',
                        $u['jamLevel']    ?? 0,
                        $u['wazersCount'] ?? 0,
                    ));
                }
                continue;
            }

            $snapshot = $this->buildSnapshot($link, $data);
            $this->em->persist($snapshot);

            $link->setLastCollectedAt(new \DateTimeImmutable());
            $this->em->flush();

            $totalSnapshots++;

            $io->writeln(sprintf(
                '  ✓ Snapshot salvo: <info>%d rota(s)</info>, <info>%d usuário(s) em jams</info>.',
                $snapshot->getRouteCount(),
                $snapshot->getTotalUsersOnJams(),
            ));
        }

        if (!$dryRun) {
            $io->success("Total salvo — Snapshots: {$totalSnapshots}");
        }

        return Command::SUCCESS;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function fetchFeed(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 30,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'WazeBR-Symfony/1.0',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("HTTP {$status} ao acessar {$url}");
        }

        return $response->toArray();
    }

    // ── Builder do Snapshot ───────────────────────────────────────────────────

    private function buildSnapshot(MonitoredLink $link, array $data): WazeTvtSnapshot
    {
        $rawRoutes = $data['routes'] ?? [];

        $snapshot = (new WazeTvtSnapshot())
            ->setPartner($link->getPartner())
            ->setSourceLink($link)
            ->setUpdateTime(isset($data['updateTime']) ? (int) $data['updateTime'] : null)
            ->setFeedName($data['name']     ?? null)
            ->setAreaName($data['areaName'] ?? null)
            ->setBroadcasterId($data['broadcasterId'] ?? null)
            ->setIsMetric((bool) ($data['isMetric'] ?? true))
            ->setBbox($data['bbox'] ?? null)
            ->setUsersOnJams($data['usersOnJams']   ?? [])
            ->setLengthOfJams($data['lengthOfJams'] ?? [])
            ->setIrregularities($data['irregularities'] ?? [])
            ->setRouteCount(count($rawRoutes));

        foreach ($rawRoutes as $rawRoute) {
            $parentWazeId = isset($rawRoute['id']) ? (string) $rawRoute['id'] : null;

            $snapshot->addRoute($this->hydrateRoute($rawRoute, false, null));

            foreach (($rawRoute['subRoutes'] ?? []) as $rawSub) {
                $snapshot->addRoute($this->hydrateRoute($rawSub, true, $parentWazeId));
            }
        }

        return $snapshot;
    }

    // ── Hidratador de WazeTvtRoute ────────────────────────────────────────────

    private function hydrateRoute(array $raw, bool $isSubRoute, ?string $parentWazeId): WazeTvtRoute
    {
        return (new WazeTvtRoute())
            ->setWazeRouteId(isset($raw['id']) ? (string) $raw['id'] : null)
            ->setIsSubRoute($isSubRoute)
            ->setParentWazeId($parentWazeId)
            ->setName($raw['name']     ?? null)
            ->setType($raw['type']     ?? null)
            ->setFromName($raw['fromName'] ?? null)
            ->setToName($raw['toName']   ?? null)
            ->setLength(isset($raw['length'])           ? (int) $raw['length']       : null)
            ->setTime(isset($raw['time'])               ? (int) $raw['time']         : null)
            ->setHistoricTime(isset($raw['historicTime']) ? (int) $raw['historicTime'] : null)
            ->setJamLevel(isset($raw['jamLevel'])       ? (int) $raw['jamLevel']     : null)
            ->setLine($raw['line'] ?? [])
            ->setBbox($raw['bbox'] ?? null)
            ->setSubRoutesRaw($raw['subRoutes'] ?? []);
    }
}
