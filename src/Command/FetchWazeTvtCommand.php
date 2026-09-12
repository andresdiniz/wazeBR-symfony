<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\WazeTvtIrregularity;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteSnapshot;
use App\Entity\WazeTvtSubRoute;
use App\Entity\WazeTvtUserOnJam;
use App\Repository\PartnerApiLinkRepository;
use App\Repository\WazeTvtRouteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta dados TVT do Waze para todos os partners que possuem um PartnerApiLink
 * com provider = 'waze_tvt'.
 *
 * Estratégia por entidade:
 *
 *   WazeTvtRoute      → INSERT on first read; UPDATE metadata nas leituras seguintes.
 *   WazeTvtSubRoute   → INSERT on first read; UPDATE campos mutáveis (tempos, jam, geometria).
 *   WazeTvtRouteSnapshot   → APPEND-ONLY (nova linha a cada coleta bem-sucedida).
 *   WazeTvtUserOnJam       → APPEND-ONLY (nova linha a cada coleta).
 *   WazeTvtIrregularity    → INSERT se contentHash ainda não existe para o par (partner, route[, subRoute]).
 *                            UPDATE updatedAt se já existe (a irregularidade foi observada novamente).
 *
 * Uso:
 *   php bin/console app:fetch:waze:tvt
 *   php bin/console app:fetch:waze:tvt --partner=42
 *   php bin/console app:fetch:waze:tvt --dry-run
 *
 * Agendamento sugerido: a cada 5-10 minutos.
 */
#[AsCommand(
    name: 'app:fetch:waze:tvt',
    description: 'Coleta dados de rotas TVT do Waze para todos os partners configurados.',
)]
class FetchWazeTvtCommand extends Command
{
    private const PROVIDER = 'waze_tvt';

    public function __construct(
        private readonly PartnerApiLinkRepository $apiLinkRepository,
        private readonly WazeTvtRouteRepository $routeRepository,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'ID do partner (processa só esse partner)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem persistir no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $partnerId = $input->getOption('partner');

        $io->title('Coleta Waze TVT');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhum dado será persistido.');
        }

        // ── 1. Selecionar partners com link TVT ──────────────────────────────
        $apiLinks = $this->apiLinkRepository->findAllByProvider(self::PROVIDER);

        if ($partnerId !== null) {
            $apiLinks = array_filter(
                $apiLinks,
                static fn ($al) => (string) $al->getPartner()->getId() === (string) $partnerId,
            );
            $apiLinks = array_values($apiLinks);
        }

        if (empty($apiLinks)) {
            $io->info('Nenhum partner com provider "waze_tvt" configurado.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processando %d partner(s) com TVT configurado.', count($apiLinks)));

        $errors = 0;

        foreach ($apiLinks as $apiLink) {
            $partner = $apiLink->getPartner();
            $label = sprintf('[Partner %d — %s]', $partner->getId(), $partner->getName());

            try {
                $io->section($label);
                $payload = $this->fetchTvtFeed($apiLink->getBaseUrl(), $apiLink->getToken());
                $this->processFeed($payload, $partner, $dryRun, $io);
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('{label}: erro ao processar feed TVT — {msg}', [
                    'label' => $label,
                    'msg' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $io->error(sprintf('%s %s', $label, $e->getMessage()));
            }
        }

        $io->success('Coleta TVT concluída.');

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // ── Feed processing ───────────────────────────────────────────────────────

    /**
     * Processa o payload completo do feed TVT para um partner.
     *
     * @param array<string, mixed> $payload
     */
    private function processFeed(
        array $payload,
        Partner $partner,
        bool $dryRun,
        SymfonyStyle $io,
    ): void {
        $recordedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $routes = $payload['routes'] ?? [];
        $usersOnJam = $payload['usersOnJam'] ?? null;

        // ── A. Persistir UsersOnJam (append-only) ────────────────────────────
        if ($usersOnJam !== null) {
            $this->persistUserOnJam($partner, $usersOnJam, null, $recordedAt, $dryRun);
        }

        // ── B. Iterar rotas ──────────────────────────────────────────────────
        foreach ($routes as $routeData) {
            $wazeRouteId = (string) ($routeData['id'] ?? '');

            if ($wazeRouteId === '') {
                $this->logger->warning('[TVT] Rota sem id no payload — ignorada.');
                continue;
            }

            // B.1 — Upsert WazeTvtRoute
            $route = $this->upsertRoute($partner, $wazeRouteId, $routeData, $dryRun);

            // B.2 — Snapshot append-only
            $this->persistSnapshot($partner, $route, $wazeRouteId, $routeData, $recordedAt, $dryRun);

            // B.3 — UsersOnJam por rota (se presente no nível da rota)
            if (isset($routeData['usersOnJam'])) {
                $this->persistUserOnJam($partner, $routeData['usersOnJam'], $route, $recordedAt, $dryRun);
            }

            // B.4 — SubRoutes upsert + irregularidades
            foreach ($routeData['subRoutes'] ?? [] as $subRouteData) {
                $wazeSubRouteId = (string) ($subRouteData['id'] ?? '');
                if ($wazeSubRouteId === '') {
                    continue;
                }

                $subRoute = $this->upsertSubRoute($partner, $route, $wazeRouteId, $wazeSubRouteId, $subRouteData, $dryRun);

                // B.4.1 — Irregularidades da subrota
                foreach ($subRouteData['irregularities'] ?? [] as $irregData) {
                    $this->persistIrregularity($partner, $route, $subRoute, $wazeRouteId, $wazeSubRouteId, $irregData, $recordedAt, $dryRun);
                }
            }

            // B.5 — Irregularidades no nível da rota
            foreach ($routeData['irregularities'] ?? [] as $irregData) {
                $this->persistIrregularity($partner, $route, null, $wazeRouteId, null, $irregData, $recordedAt, $dryRun);
            }

            $io->writeln(sprintf(
                '  Rota %s — subRoutes: %d, irregularidades: %d',
                $wazeRouteId,
                count($routeData['subRoutes'] ?? []),
                count($routeData['irregularities'] ?? []),
            ));
        }

        if (!$dryRun) {
            $this->em->flush();
        }
    }

    // ── Persistência por entidade ─────────────────────────────────────────────

    /**
     * INSERT na primeira leitura; UPDATE metadados nas subsequentes.
     *
     * @param array<string, mixed> $data
     */
    private function upsertRoute(
        Partner $partner,
        string $wazeRouteId,
        array $data,
        bool $dryRun,
    ): WazeTvtRoute {
        $route = $this->routeRepository->findOneByPartnerAndRouteId($partner, $wazeRouteId);

        if ($route === null) {
            $route = new WazeTvtRoute();
            $route->setPartner($partner);
            $route->setRouteId($wazeRouteId);

            if (!$dryRun) {
                $this->em->persist($route);
            }
        }

        // Atualiza metadados mutáveis em todas as leituras
        $route->setName($data['name'] ?? null);
        $route->setFromName($data['fromName'] ?? null);
        $route->setToName($data['toName'] ?? null);
        $route->setLength($data['length'] ?? null);
        $route->setGeometry($data['line'] ?? null);

        return $route;
    }

    /**
     * INSERT na primeira leitura; UPDATE campos mutáveis (tempos, jam, geometria) nas subsequentes.
     *
     * @param array<string, mixed> $data
     */
    private function upsertSubRoute(
        Partner $partner,
        WazeTvtRoute $route,
        string $wazeRouteId,
        string $wazeSubRouteId,
        array $data,
        bool $dryRun,
    ): WazeTvtSubRoute {
        $subRoute = $this->em->getRepository(WazeTvtSubRoute::class)->findOneBy([
            'partner' => $partner,
            'route' => $route,
            'subRouteId' => $wazeSubRouteId,
        ]);

        if ($subRoute === null) {
            $subRoute = new WazeTvtSubRoute();
            $subRoute->setPartner($partner);
            $subRoute->setRoute($route);
            $subRoute->setWazeRouteId($wazeRouteId);
            $subRoute->setSubRouteId($wazeSubRouteId);

            if (!$dryRun) {
                $this->em->persist($subRoute);
            }
        }

        // Atualiza campos mutáveis
        $subRoute->setName($data['name'] ?? null);
        $subRoute->setFromName($data['fromName'] ?? null);
        $subRoute->setToName($data['toName'] ?? null);
        $subRoute->setLength($data['length'] ?? null);
        $subRoute->setTime($data['time'] ?? null);
        $subRoute->setHistoricTime($data['historicTime'] ?? null);
        $subRoute->setJamLevel($data['jamLevel'] ?? null);
        $subRoute->setLine($data['line'] ?? null);
        $subRoute->setBbox($data['bbox'] ?? null);
        $subRoute->setIrregularities($data['irregularities'] ?? null);

        return $subRoute;
    }

    /**
     * Append-only: persiste um novo snapshot por leitura.
     *
     * @param array<string, mixed> $data
     */
    private function persistSnapshot(
        Partner $partner,
        WazeTvtRoute $route,
        string $wazeRouteId,
        array $data,
        \DateTimeImmutable $recordedAt,
        bool $dryRun,
    ): void {
        $snapshot = new WazeTvtRouteSnapshot();
        $snapshot->setPartner($partner);
        $snapshot->setRoute($route);
        $snapshot->setWazeRouteId($wazeRouteId);
        $snapshot->setTime($data['time'] ?? null);
        $snapshot->setHistoricTime($data['historicTime'] ?? null);
        $snapshot->setJamLevel($data['jamLevel'] ?? null);
        $snapshot->setRecordedAt($recordedAt);

        if (!$dryRun) {
            $this->em->persist($snapshot);
        }
    }

    /**
     * Append-only: persiste observação de users-on-jams.
     *
     * @param array<string, mixed> $data
     */
    private function persistUserOnJam(
        Partner $partner,
        array $data,
        ?WazeTvtRoute $route,
        \DateTimeImmutable $recordedAt,
        bool $dryRun,
    ): void {
        $entity = new WazeTvtUserOnJam();
        $entity->setPartner($partner);
        $entity->setRoute($route);
        $entity->setWazeRouteId($route?->getRouteId());
        $entity->setWazersCount((int) ($data['wazersCount'] ?? $data['count'] ?? 0));
        $entity->setJamLevel($data['jamLevel'] ?? null);
        $entity->setRecordedAt($recordedAt);

        if (!$dryRun) {
            $this->em->persist($entity);
        }
    }

    /**
     * INSERT se o contentHash ainda não existe para o contexto (partner, route, subRoute).
     * UPDATE updatedAt se a irregularidade já foi vista (mesma hash).
     *
     * @param array<string, mixed> $data
     */
    private function persistIrregularity(
        Partner $partner,
        WazeTvtRoute $route,
        ?WazeTvtSubRoute $subRoute,
        string $wazeRouteId,
        ?string $wazeSubRouteId,
        array $data,
        \DateTimeImmutable $recordedAt,
        bool $dryRun,
    ): void {
        $contentHash = $this->computeContentHash($data);

        $criteria = [
            'partner' => $partner,
            'route' => $route,
            'subRoute' => $subRoute,
            'contentHash' => $contentHash,
        ];

        $existing = $this->em->getRepository(WazeTvtIrregularity::class)->findOneBy($criteria);

        if ($existing !== null) {
            // Irregularidade observada novamente — apenas atualiza updatedAt
            $existing->setUpdatedAt($recordedAt);
            return;
        }

        $irregularity = new WazeTvtIrregularity();
        $irregularity->setPartner($partner);
        $irregularity->setRoute($route);
        $irregularity->setSubRoute($subRoute);
        $irregularity->setWazeRouteId($wazeRouteId);
        $irregularity->setWazeSubRouteId($wazeSubRouteId);
        $irregularity->setType($data['type'] ?? null);
        $irregularity->setSubtype($data['subtype'] ?? null);
        $irregularity->setDescription($data['description'] ?? null);
        $irregularity->setPayload($data);
        $irregularity->setContentHash($contentHash);
        $irregularity->setRecordedAt($recordedAt);
        $irregularity->setIsActive(true);

        if (!$dryRun) {
            $this->em->persist($irregularity);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Calcula SHA-256 do payload canonicalizado.
     * Exclui campos efémeros (timestamps variáveis) para que a hash represente
     * o conteúdo da irregularidade, não o instante em que foi observada.
     *
     * @param array<string, mixed> $data
     */
    private function computeContentHash(array $data): string
    {
        $canonical = $data;
        unset($canonical['recordedAt'], $canonical['updatedAt'], $canonical['createdAt']);
        ksort($canonical);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Busca o feed TVT do Waze na URL configurada no PartnerApiLink.
     *
     * @return array<string, mixed>
     */
    private function fetchTvtFeed(string $baseUrl, ?string $token): array
    {
        $options = ['timeout' => 15];

        if ($token !== null && $token !== '') {
            $options['headers'] = ['Authorization' => 'Bearer ' . $token];
        }

        $response = $this->httpClient->request('GET', $baseUrl, $options);

        return $response->toArray();
    }
}
