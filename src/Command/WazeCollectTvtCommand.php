<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitoredLink;
use App\Entity\Partner;
use App\Entity\WazeCount;
use App\Entity\WazeRoute;
use App\Entity\WazeSubRoute;
use App\Repository\MonitoredLinkRepository;
use App\Repository\PartnerRepository;
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
 * Coleta rotas, sub-rotas e contagens de usuários do feed Waze TVT (feeds-tvt).
 *
 * URL do feed (type='tvt' em MonitoredLink):
 *   https://www.waze.com/row-partnerhub-api/feeds-tvt/{uuid}?id={monitorId}
 *
 * Estrutura do JSON retornado:
 *   {
 *     "usersOnJams": [
 *       { "wazersCount": 193, "jamLevel": 0 },
 *       { "wazersCount": 0,   "jamLevel": 1 },
 *       ...
 *     ],
 *     "routes": [
 *       {
 *         "id": "1762938283029",   // string ou ausente
 *         "name": "Rota 2",
 *         "fromName": "Av. Monsenhor Moreira",
 *         "toName": "R. Valério Eugênio",
 *         "type": "STATIC",
 *         "time": 132,
 *         "historicTime": 104,
 *         "length": 209,
 *         "jamLevel": 3,
 *         "line": [ {"x": -43.80, "y": -20.65}, ... ],
 *         "bbox": { "minY": ..., "minX": ..., "maxY": ..., "maxX": ... },
 *         "alternateRoute": { ... },   // opcional
 *         "subRoutes": [
 *           {
 *             "fromName": "Av. Monsenhor Moreira",
 *             "toName": "R. Valério Eugênio",
 *             "time": 45,
 *             "historicTime": 32,
 *             "length": 49,
 *             "jamLevel": 0,
 *             "line": [ ... ],
 *             "bbox": { ... }
 *           },
 *           ...
 *         ]
 *       },
 *       ...
 *     ]
 *   }
 *
 * ESTRATÉGIA DE DEDUPLICAÇÃO:
 *   - WazeRoute: por (wazeId + partner). Rotas sem "id" são sempre inseridas.
 *   - WazeCount: sem deduplicação — é um snapshot por coleta (série temporal).
 *   - WazeSubRoute: removidas e reinseridas a cada coleta da rota pai (mutable).
 */
#[AsCommand(
    name: 'app:waze:collect-tvt',
    description: 'Coleta rotas e contagens do feed TVT Waze para todos os links ativos do tipo "tvt".',
)]
class WazeCollectTvtCommand extends Command
{
    public function __construct(
        private readonly PartnerRepository       $partnerRepo,
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly WazeRouteRepository     $routeRepo,
        private readonly EntityManagerInterface  $em,
        private readonly HttpClientInterface     $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', 'p', InputOption::VALUE_OPTIONAL,
                'Slug do parceiro — omitir para processar todos os ativos')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Busca e exibe o JSON sem persistir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $slug   = $input->getOption('partner');

        $io->title('Waze Collect TVT — feeds-tvt');
        if ($dryRun) {
            $io->note('Modo DRY-RUN: nenhum dado será persistido.');
        }

        $partners = $slug
            ? array_filter(
                $this->partnerRepo->findActivePartners(),
                fn (Partner $p) => $p->getSlug() === $slug,
            )
            : $this->partnerRepo->findActivePartners();

        if (empty($partners)) {
            $io->warning('Nenhum parceiro ativo encontrado.');
            return Command::SUCCESS;
        }

        $totalRoutes = 0;
        $totalCounts = 0;

        foreach ($partners as $partner) {
            $io->section("Parceiro: {$partner->getName()} [{$partner->getSlug()}]");

            /** @var MonitoredLink[] $links */
            $links = $this->linkRepo->findBy([
                'partner'  => $partner,
                'type'     => 'tvt',
                'isActive' => true,
            ]);

            if (empty($links)) {
                $io->warning('Nenhum link TVT ativo. Cadastre um MonitoredLink do tipo "tvt".');
                continue;
            }

            foreach ($links as $link) {
                $io->writeln("  → [{$link->getName()}] {$link->getUrl()}");

                try {
                    $data = $this->fetchFeed($link->getUrl());
                } catch (\Throwable $e) {
                    $io->error('  ✗ Erro ao buscar feed TVT: ' . $e->getMessage());
                    if (!$dryRun) {
                        $link->markError($e->getMessage());
                        $this->em->flush();
                    }
                    continue;
                }

                $rawRoutes      = $data['routes']      ?? [];
                $rawUsersOnJams = $data['usersOnJams'] ?? [];

                if ($dryRun) {
                    $io->writeln(sprintf(
                        '  DRY-RUN: %d rotas, %d níveis de contagem encontrados.',
                        count($rawRoutes),
                        count($rawUsersOnJams),
                    ));

                    foreach ($rawRoutes as $i => $r) {
                        $io->writeln(sprintf(
                            '    Rota %d: id=%s | name=%s | jamLevel=%s | subRoutes=%d',
                            $i + 1,
                            $r['id']      ?? '(sem id)',
                            $r['name']    ?? '(sem nome)',
                            $r['jamLevel'] ?? '?',
                            count($r['subRoutes'] ?? []),
                        ));
                    }

                    $io->writeln('  usersOnJams:');
                    foreach ($rawUsersOnJams as $u) {
                        $io->writeln(sprintf(
                            '    jamLevel=%d → %s usuários',
                            $u['jamLevel']    ?? '?',
                            $u['wazersCount'] ?? '?',
                        ));
                    }

                    continue;
                }

                $now = new \DateTime();

                $routes = $this->persistRoutes($partner, $rawRoutes, $now);
                $counts = $this->persistCount($rawUsersOnJams, $now);

                $link->markSuccess($routes + $counts);
                $this->em->flush();

                $totalRoutes += $routes;
                $totalCounts += $counts;

                $io->writeln(sprintf(
                    '  ✓ Rotas: <info>%d novas/atualizadas</info> | Contagens: <info>%d snapshot(s)</info>',
                    $routes,
                    $counts,
                ));
            }
        }

        if (!$dryRun) {
            $io->success("Total salvo — Rotas: {$totalRoutes} | Snapshots de contagem: {$totalCounts}");
        }

        return Command::SUCCESS;
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

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

    // ── Persistência — Rotas ──────────────────────────────────────────────────

    /**
     * Insere ou atualiza WazeRoute e reinicia seus WazeSubRoutes.
     *
     * Deduplicação:
     *  - Se a rota tem "id" (wazeId) já existente para o parceiro → atualiza os campos e
     *    remove+reinserece as subRoutes (os dados de tempo mudam a cada coleta).
     *  - Se a rota NÃO tem "id" → insere sempre como novo snapshot.
     */
    private function persistRoutes(Partner $partner, array $rawRoutes, \DateTime $now): int
    {
        $count = 0;

        foreach ($rawRoutes as $raw) {
            $wazeId = isset($raw['id']) ? (string) $raw['id'] : null;

            // Tenta recuperar rota existente (só quando há wazeId)
            $route = null;
            if ($wazeId !== null) {
                $route = $this->routeRepo->findOneBy(['wazeId' => $wazeId]);
            }

            if ($route === null) {
                $route = new WazeRoute();
                $route->setWazeId($wazeId);
            }

            // ── Hidrata campos da rota ──────────────────────────────────────
            $route
                ->setName(isset($raw['name']) ? mb_substr((string) $raw['name'], 0, 255) : null)
                ->setFromName(isset($raw['fromName']) ? mb_substr((string) $raw['fromName'], 0, 255) : null)
                ->setToName(isset($raw['toName']) ? mb_substr((string) $raw['toName'], 0, 255) : null)
                ->setType(isset($raw['type']) ? mb_substr((string) $raw['type'], 0, 30) : null)
                ->setTime(isset($raw['time']) ? (int) $raw['time'] : null)
                ->setHistoricTime(isset($raw['historicTime']) ? (int) $raw['historicTime'] : null)
                ->setLength(isset($raw['length']) ? (int) $raw['length'] : null)
                ->setJamLevel(isset($raw['jamLevel']) ? (int) $raw['jamLevel'] : null)
                ->setLine($raw['line'] ?? null)
                ->setBbox($raw['bbox'] ?? null)
                ->setAlternateRoute($raw['alternateRoute'] ?? null)
                ->setCollectedAt($now);

            // ── Reinicia subRoutes ──────────────────────────────────────────
            // Remove todas as subRoutes existentes (orphanRemoval=true garante o DELETE)
            foreach ($route->getSubRoutes() as $old) {
                $route->removeSubRoute($old);
            }

            // Insere subRoutes da coleta atual
            foreach (($raw['subRoutes'] ?? []) as $sortOrder => $rawSub) {
                $sub = $this->hydrateSubRoute($rawSub, $sortOrder);
                $route->addSubRoute($sub);
            }

            $this->em->persist($route);
            $count++;
        }

        if ($count > 0) {
            $this->em->flush();
        }

        return $count;
    }

    // ── Hidratador de SubRoute ────────────────────────────────────────────────

    private function hydrateSubRoute(array $raw, int $sortOrder): WazeSubRoute
    {
        $sub = new WazeSubRoute();

        $sub
            ->setFromName(isset($raw['fromName']) ? mb_substr((string) $raw['fromName'], 0, 255) : null)
            ->setToName(isset($raw['toName']) ? mb_substr((string) $raw['toName'], 0, 255) : null)
            ->setTime(isset($raw['time']) ? (int) $raw['time'] : null)
            ->setHistoricTime(isset($raw['historicTime']) ? (int) $raw['historicTime'] : null)
            ->setLength(isset($raw['length']) ? (int) $raw['length'] : null)
            ->setJamLevel(isset($raw['jamLevel']) ? (int) $raw['jamLevel'] : null)
            ->setLine($raw['line'] ?? null)
            ->setBbox($raw['bbox'] ?? null)
            ->setSortOrder($sortOrder);

        return $sub;
    }

    // ── Persistência — WazeCount (snapshot) ───────────────────────────────────

    /**
     * Sempre insere um novo snapshot de WazeCount — é uma série temporal,
     * nunca atualiza um registro existente.
     */
    private function persistCount(array $rawUsersOnJams, \DateTime $now): int
    {
        if (empty($rawUsersOnJams)) {
            return 0;
        }

        $count = (new WazeCount())
            ->setCollectedAt($now)
            ->hydrateFromApiArray($rawUsersOnJams);

        $this->em->persist($count);
        $this->em->flush();

        return 1;
    }
}
