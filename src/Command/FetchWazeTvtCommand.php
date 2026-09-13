<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\PartnerApiLink;
use App\Entity\WazeTvtIrregularity;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteSnapshot;
use App\Entity\WazeTvtSubRoute;
use App\Entity\WazeTvtUserOnJam;
use App\Repository\PartnerApiLinkRepository;
use App\Repository\WazeTvtIrregularityRepository;
use App\Repository\WazeTvtRouteRepository;
use App\Repository\WazeTvtRouteSnapshotRepository;
use App\Repository\WazeTvtSubRouteRepository;
use App\Repository\WazeTvtUserOnJamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:fetch:waze:tvt',
    description: 'Coleta e sincroniza dados de rotas TVT do Waze.',
)]
final class FetchWazeTvtCommand extends Command
{
    private const TYPE = 'TVT';

    public function __construct(
        private readonly PartnerApiLinkRepository $apiLinkRepository,
        private readonly WazeTvtRouteRepository $routeRepository,
        private readonly WazeTvtSubRouteRepository $subRouteRepository,
        private readonly WazeTvtIrregularityRepository $irregularityRepository,
        private readonly WazeTvtRouteSnapshotRepository $snapshotRepository,
        private readonly WazeTvtUserOnJamRepository $userOnJamRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'partner',
                null,
                InputOption::VALUE_OPTIONAL,
                'ID do partner.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Executa sem persistir no banco.',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Ignora temporariamente a frequência configurada no partner (uso manual).',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $this->resetStaleConnection();
        $partnerId = $input->getOption('partner');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $io->title('Coleta Waze TVT');

        if ($dryRun) {
            $io->warning(
                'Modo DRY-RUN: nenhum dado será gravado, atualizado ou desativado.',
            );
        }

        $apiLinks = $this->apiLinkRepository->findAllByType(self::TYPE);

        if ($partnerId !== null) {
            $apiLinks = array_values(array_filter(
                $apiLinks,
                static function (PartnerApiLink $apiLink) use ($partnerId): bool {
                    $partner = $apiLink->getPartner();

                    return $partner !== null
                        && (string) $partner->getId() === (string) $partnerId;
                },
            ));
        }

        if ($apiLinks === []) {
            $io->info(
                sprintf(
                    'Nenhum link ativo com type "%s" foi encontrado.',
                    self::TYPE,
                ),
            );

            return Command::SUCCESS;
        }

        /*
         * Agrupa os links por partner para que o lock e o filtro de
         * frequência sejam aplicados uma vez por partner, e não por link.
         */
        $linksByPartner = [];

        foreach ($apiLinks as $apiLink) {
            $partner = $apiLink->getPartner();

            if ($partner === null || $partner->getId() === null) {
                continue;
            }

            $linksByPartner[$partner->getId()][] = $apiLink;
        }

        $io->info(sprintf(
            'Processando %d partner(s) com link TVT.',
            count($linksByPartner),
        ));

        $errors = 0;
        $processed = 0;
        $skipped = 0;
        $locked = 0;

        foreach ($linksByPartner as $links) {
            $partner = $links[0]->getPartner();

            if ($partner === null || $partner->getId() === null) {
                continue;
            }

            $label = sprintf(
                '[Partner %d — %s]',
                $partner->getId(),
                $partner->getName(),
            );

            /*
             * Lock exclusivo por partner. TTL de 10 min: se um processo
             * morrer no meio, o lock expira sozinho.
             */
            $lock = $this->lockFactory->createLock(
                sprintf('waze:tvt:partner:%d', $partner->getId()),
                ttl: 600.0,
            );

            if (!$lock->acquire()) {
                $io->note(sprintf(
                    '%s ignorado: outro processo já está sincronizando.',
                    $label,
                ));

                $locked++;

                continue;
            }

            try {
                if (
                    !$force
                    && !$partner->isFetchDue($partner->getLastTvtFetchAt())
                ) {
                    $io->note(sprintf(
                        '%s ignorado: frequência ainda não vencida (%s).',
                        $label,
                        $partner->getFetchFrequencyLabel(),
                    ));

                    $skipped++;

                    continue;
                }

                $io->section($label);

                $partnerSuccess = true;

                foreach ($links as $apiLink) {
                    try {
                        $payload = $this->fetchTvtFeed(
                            (string) $apiLink->getUrl(),
                        );

                        $result = $this->processFeed(
                            $payload,
                            $partner,
                            $dryRun,
                            $io,
                        );

                        $io->table(
                            ['Métrica', 'Quantidade'],
                            [
                                ['Rotas novas', $result['routesCreated']],
                                ['Rotas reativadas', $result['routesReactivated']],
                                ['Rotas desativadas', $result['routesDeactivated']],
                                ['Subrotas novas', $result['subRoutesCreated']],
                                ['Subrotas reativadas', $result['subRoutesReactivated']],
                                ['Subrotas desativadas', $result['subRoutesDeactivated']],
                                ['Irregularidades novas', $result['irregularitiesCreated']],
                                ['Irregularidades reativadas', $result['irregularitiesReactivated']],
                                ['Irregularidades desativadas', $result['irregularitiesDeactivated']],
                                ['Snapshots gravados', $result['snapshotsCreated']],
                                ['Usuários em jam gravados', $result['usersOnJamCreated']],
                            ],
                        );
                    } catch (\Throwable $exception) {
                        $partnerSuccess = false;
                        $errors++;

                        $this->logger->error(
                            '{label}: erro no feed TVT: {message}',
                            [
                                'label' => $label,
                                'message' => $exception->getMessage(),
                                'exception' => $exception,
                            ],
                        );

                        $io->error(sprintf(
                            '%s %s',
                            $label,
                            $exception->getMessage(),
                        ));
                    }
                }

                if ($partnerSuccess) {
                    if (!$dryRun) {
                        $partner->setLastTvtFetchAt(new \DateTimeImmutable());

                        $this->entityManager->persist($partner);
                        $this->entityManager->flush();
                    }

                    $processed++;
                }
            } finally {
                $lock->release();
            }
        }

        $io->section('Resumo');

        $io->table(
            ['Métrica', 'Quantidade'],
            [
                ['Partners processados', $processed],
                ['Partners ignorados (frequência)', $skipped],
                ['Partners ignorados (lock)', $locked],
                ['Erros', $errors],
            ],
        );

        if ($errors > 0) {
            return Command::FAILURE;
        }

        $io->success('Coleta TVT concluída.');

        return Command::SUCCESS;
    }

    /**
     * @return array{
     *     routesCreated: int,
     *     routesReactivated: int,
     *     routesDeactivated: int,
     *     subRoutesCreated: int,
     *     subRoutesReactivated: int,
     *     subRoutesDeactivated: int,
     *     irregularitiesCreated: int,
     *     irregularitiesReactivated: int,
     *     irregularitiesDeactivated: int,
     *     snapshotsCreated: int,
     *     usersOnJamCreated: int
     * }
     */
    private function processFeed(
        array $payload,
        Partner $partner,
        bool $dryRun,
        SymfonyStyle $io,
    ): array {
        $recordedAt = new \DateTimeImmutable(
            'now',
            new \DateTimeZone('UTC'),
        );

        $routesData = $payload['routes'] ?? [];

        if (!is_array($routesData)) {
            throw new \RuntimeException(
                'O campo "routes" não é uma lista válida.',
            );
        }

        $result = [
            'routesCreated' => 0,
            'routesReactivated' => 0,
            'routesDeactivated' => 0,
            'subRoutesCreated' => 0,
            'subRoutesReactivated' => 0,
            'subRoutesDeactivated' => 0,
            'irregularitiesCreated' => 0,
            'irregularitiesReactivated' => 0,
            'irregularitiesDeactivated' => 0,
            'snapshotsCreated' => 0,
            'usersOnJamCreated' => 0,
        ];

        /*
         * O JSON usa usersOnJams, no plural.
         */
        $usersOnJams = $payload['usersOnJams'] ?? null;

        if (is_array($usersOnJams)) {
            $result['usersOnJamCreated'] += $this->persistUserOnJam(
                $partner,
                $usersOnJams,
                null,
                $recordedAt,
                $dryRun,
            );
        }

        /*
         * Irregularidades no root não possuem rota associada; apenas
         * registramos um aviso para não perder o dado silenciosamente.
         */
        $rootIrregularities = $payload['irregularities'] ?? null;

        if (is_array($rootIrregularities) && $rootIrregularities !== []) {
            $this->logger->warning(
                '[TVT] Payload trouxe irregularidades no root; ignoradas por não terem rota associada.',
                ['count' => count($rootIrregularities)],
            );
        }

        $currentRouteIds = [];

        foreach ($routesData as $routeData) {
            if (!is_array($routeData)) {
                continue;
            }

            $wazeRouteId = trim(
                (string) ($routeData['id'] ?? ''),
            );

            if ($wazeRouteId === '') {
                $this->logger->warning(
                    '[TVT] Rota sem id no payload; ignorada.',
                );

                continue;
            }

            $currentRouteIds[] = $wazeRouteId;

            $routeResult = $this->upsertRoute(
                $partner,
                $wazeRouteId,
                $routeData,
                $recordedAt,
                $dryRun,
            );

            $route = $routeResult['entity'];

            $result['routesCreated'] += $routeResult['created'];
            $result['routesReactivated'] += $routeResult['reactivated'];

            if (
                $this->persistSnapshot(
                    $partner,
                    $route,
                    $wazeRouteId,
                    $routeData,
                    $recordedAt,
                    $dryRun,
                )
            ) {
                $result['snapshotsCreated']++;
            }

            /*
             * Caso uma rota também possua usersOnJams.
             */
            $routeUsersOnJams = $routeData['usersOnJams'] ?? null;

            if (is_array($routeUsersOnJams)) {
                $result['usersOnJamCreated'] += $this->persistUserOnJam(
                    $partner,
                    $routeUsersOnJams,
                    $route,
                    $recordedAt,
                    $dryRun,
                );
            }

            $currentSubRouteIds = [];
            $currentRouteIrregularityHashes = [];

            foreach (
                ($routeData['subRoutes'] ?? []) as $subRouteData
            ) {
                if (!is_array($subRouteData)) {
                    continue;
                }

                $wazeSubRouteId = $this->computeSubRouteId($subRouteData);

                if ($wazeSubRouteId === '') {
                    continue;
                }

                $currentSubRouteIds[] = $wazeSubRouteId;

                $subRouteResult = $this->upsertSubRoute(
                    $partner,
                    $route,
                    $wazeRouteId,
                    $wazeSubRouteId,
                    $subRouteData,
                    $recordedAt,
                    $dryRun,
                );

                $subRoute = $subRouteResult['entity'];

                $result['subRoutesCreated'] += $subRouteResult['created'];
                $result['subRoutesReactivated'] += $subRouteResult['reactivated'];

                $currentIrregularityHashes = [];

                foreach (
                    ($subRouteData['irregularities'] ?? []) as $irregData
                ) {
                    if (!is_array($irregData)) {
                        continue;
                    }

                    $irregularityResult = $this->upsertIrregularity(
                        $partner,
                        $route,
                        $subRoute,
                        $wazeRouteId,
                        $wazeSubRouteId,
                        $irregData,
                        $recordedAt,
                        $dryRun,
                    );

                    $currentIrregularityHashes[] =
                        $irregularityResult['hash'];

                    $result['irregularitiesCreated'] +=
                        $irregularityResult['created'];

                    $result['irregularitiesReactivated'] +=
                        $irregularityResult['reactivated'];
                }

                if (!$dryRun && $currentIrregularityHashes !== []) {
                    $result['irregularitiesDeactivated'] +=
                        $this->irregularityRepository
                            ->deactivateMissingForScope(
                                $partner,
                                $route,
                                $subRoute,
                                array_values(
                                    array_unique(
                                        $currentIrregularityHashes,
                                    ),
                                ),
                                $recordedAt,
                            );
                }
            }

            if (!$dryRun && $currentSubRouteIds !== []) {
                $result['subRoutesDeactivated'] +=
                    $this->subRouteRepository
                        ->deactivateMissingForRoute(
                            $partner,
                            $route,
                            array_values(
                                array_unique($currentSubRouteIds),
                            ),
                            $recordedAt,
                        );
            }

            foreach (
                ($routeData['irregularities'] ?? []) as $irregData
            ) {
                if (!is_array($irregData)) {
                    continue;
                }

                $irregularityResult = $this->upsertIrregularity(
                    $partner,
                    $route,
                    null,
                    $wazeRouteId,
                    null,
                    $irregData,
                    $recordedAt,
                    $dryRun,
                );

                $currentRouteIrregularityHashes[] =
                    $irregularityResult['hash'];

                $result['irregularitiesCreated'] +=
                    $irregularityResult['created'];

                $result['irregularitiesReactivated'] +=
                    $irregularityResult['reactivated'];
            }

            if (
                !$dryRun
                && $currentRouteIrregularityHashes !== []
            ) {
                $result['irregularitiesDeactivated'] +=
                    $this->irregularityRepository
                        ->deactivateMissingForScope(
                            $partner,
                            $route,
                            null,
                            array_values(
                                array_unique(
                                    $currentRouteIrregularityHashes,
                                ),
                            ),
                            $recordedAt,
                        );
            }

            $io->writeln(sprintf(
                'Rota %s — subRoutes: %d, irregularidades: %d',
                $wazeRouteId,
                count($routeData['subRoutes'] ?? []),
                count($routeData['irregularities'] ?? []),
            ));
        }

        if (
            !$dryRun
            && $currentRouteIds !== []
        ) {
            $result['routesDeactivated'] =
                $this->routeRepository
                    ->deactivateMissingForPartner(
                        $partner,
                        array_values(
                            array_unique($currentRouteIds),
                        ),
                        $recordedAt,
                    );
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $result;
    }

    /**
     * @return array{
     *     entity: WazeTvtRoute,
     *     created: int,
     *     reactivated: int
     * }
     */
    private function upsertRoute(
        Partner $partner,
        string $wazeRouteId,
        array $data,
        \DateTimeImmutable $recordedAt,
        bool $dryRun,
    ): array {
        $route = $this->routeRepository
            ->findOneByPartnerAndRouteId(
                $partner,
                $wazeRouteId,
            );

        $created = 0;
        $reactivated = 0;

        if ($route === null) {
            $route = new WazeTvtRoute();

            $route
                ->setPartner($partner)
                ->setRouteId($wazeRouteId);

            $created++;

            if (!$dryRun) {
                $this->entityManager->persist($route);
            }
        } elseif (!$route->isActive()) {
            $reactivated++;
        }

        $route
            ->setName($data['name'] ?? null)
            ->setFromName($data['fromName'] ?? null)
            ->setToName($data['toName'] ?? null)
            ->setLength(
                isset($data['length'])
                    ? (int) $data['length']
                    : null,
            )
            ->setGeometry(
                is_array($data['line'] ?? null)
                    ? $data['line']
                    : null,
            )
            ->setIsActive(true)
            ->setLastSeenAt($recordedAt);

        return [
            'entity' => $route,
            'created' => $created,
            'reactivated' => $reactivated,
        ];
    }

    /**
     * @return array{
     *     entity: WazeTvtSubRoute,
     *     created: int,
     *     reactivated: int
     * }
     */
    private function upsertSubRoute(
        Partner $partner,
        WazeTvtRoute $route,
        string $wazeRouteId,
        string $wazeSubRouteId,
        array $data,
        \DateTimeImmutable $recordedAt,
        bool $dryRun,
    ): array {
        $subRoute = $this->subRouteRepository
            ->findOneByIdentity(
                $partner,
                $route,
                $wazeSubRouteId,
            );

        $created = 0;
        $reactivated = 0;

        if ($subRoute === null) {
            $subRoute = new WazeTvtSubRoute();

            $subRoute
                ->setPartner($partner)
                ->setRoute($route)
                ->setWazeRouteId($wazeRouteId)
                ->setSubRouteId($wazeSubRouteId);

            $created++;

            if (!$dryRun) {
                $this->entityManager->persist($subRoute);
            }
        } elseif (!$subRoute->isActive()) {
            $reactivated++;
        }

        $subRoute
            ->setName($data['name'] ?? null)
            ->setFromName($data['fromName'] ?? null)
            ->setToName($data['toName'] ?? null)
            ->setLength(
                isset($data['length'])
                    ? (int) $data['length']
                    : null,
            )
            ->setTime(
                isset($data['time'])
                    ? (int) $data['time']
                    : null,
            )
            ->setHistoricTime(
                isset($data['historicTime'])
                    ? (int) $data['historicTime']
                    : null,
            )
            ->setJamLevel(
                isset($data['jamLevel'])
                    ? (int) $data['jamLevel']
                    : null,
            )
            ->setLine(
                is_array($data['line'] ?? null)
                    ? $data['line']
                    : null,
            )
            ->setBbox(
                is_array($data['bbox'] ?? null)
                    ? $data['bbox']
                    : null,
            )
            ->setIrregularities(
                is_array($data['irregularities'] ?? null)
                    ? $data['irregularities']
                    : null,
            )
            ->setIsActive(true)
            ->setLastSeenAt($recordedAt);

        return [
            'entity' => $subRoute,
            'created' => $created,
            'reactivated' => $reactivated,
        ];
    }

    private function persistSnapshot(
        Partner $partner,
        WazeTvtRoute $route,
        string $wazeRouteId,
        array $data,
        \DateTimeImmutable $recordedAt,
        bool $dryRun,
    ): bool {
        if ($dryRun) {
            return false;
        }

        $snapshot = new WazeTvtRouteSnapshot();

        $snapshot
            ->setPartner($partner)
            ->setRoute($route)
            ->setWazeRouteId($wazeRouteId)
            ->setName($data['name'] ?? null)
            ->setCity($data['city'] ?? null)
            ->setState($data['state'] ?? null)
            ->setTime(
                isset($data['time'])
                    ? (int) $data['time']
                    : null,
            )
            ->setHistoricTime(
                isset($data['historicTime'])
                    ? (int) $data['historicTime']
                    : null,
            )
            ->setJamLevel(
                isset($data['jamLevel'])
                    ? (int) $data['jamLevel']
                    : null,
            )
            ->setPayload($data)
            ->setRecordedAt($recordedAt);

        $this->entityManager->persist($snapshot);

        return true;
    }

    private function persistUserOnJam(
        Partner $partner,
        array $data,
        ?WazeTvtRoute $route,
        \DateTimeImmutable $recordedAt,
        bool $dryRun,
    ): int {
        if ($dryRun) {
            return 0;
        }

        $entity = new WazeTvtUserOnJam();

        $entity
            ->setPartner($partner)
            ->setRoute($route)
            ->setWazeRouteId($route?->getRouteId())
            ->setWazersCount(
                (int) (
                    $data['wazersCount']
                    ?? $data['wazers_count']
                    ?? $data['count']
                    ?? 0
                ),
            )
            ->setJamLevel(
                isset($data['jamLevel'])
                    ? (int) $data['jamLevel']
                    : (
                        isset($data['jam_level'])
                            ? (int) $data['jam_level']
                            : null
                    ),
            )
            ->setPayload($data)
            ->setRecordedAt($recordedAt);

        $this->entityManager->persist($entity);

        return 1;
    }

    /**
     * @return array{
     *     hash: string,
     *     created: int,
     *     reactivated: int
     * }
     */
    private function upsertIrregularity(
        Partner $partner,
        WazeTvtRoute $route,
        ?WazeTvtSubRoute $subRoute,
        string $wazeRouteId,
        ?string $wazeSubRouteId,
        array $data,
        \DateTimeImmutable $recordedAt,
        bool $dryRun,
    ): array {
        $contentHash = $this->computeContentHash($data);

        if ($dryRun) {
            return [
                'hash' => $contentHash,
                'created' => 0,
                'reactivated' => 0,
            ];
        }

        $existing = $this->irregularityRepository
            ->findOneByContentHash(
                $partner,
                $route,
                $subRoute,
                $contentHash,
            );

        $created = 0;
        $reactivated = 0;

        if ($existing === null) {
            $existing = new WazeTvtIrregularity();

            $existing
                ->setPartner($partner)
                ->setRoute($route)
                ->setSubRoute($subRoute)
                ->setContentHash($contentHash);

            $this->entityManager->persist($existing);
            $created++;
        } elseif (!$existing->isActive()) {
            $reactivated++;
        }

        $existing
            ->setWazeRouteId($wazeRouteId)
            ->setWazeSubRouteId($wazeSubRouteId)
            ->setType($data['type'] ?? null)
            ->setSubtype($data['subtype'] ?? null)
            ->setSeverity($data['severity'] ?? null)
            ->setDescription($data['description'] ?? null)
            ->setStreet($data['street'] ?? null)
            ->setCity($data['city'] ?? null)
            ->setState($data['state'] ?? null)
            ->setLatitude(
                isset($data['latitude'])
                    ? (float) $data['latitude']
                    : null,
            )
            ->setLongitude(
                isset($data['longitude'])
                    ? (float) $data['longitude']
                    : null,
            )
            ->setPayload($data)
            ->setIsActive(true)
            ->setRecordedAt($recordedAt)
            ->setLastSeenAt($recordedAt)
            ->setUpdatedAt($recordedAt);

        return [
            'hash' => $contentHash,
            'created' => $created,
            'reactivated' => $reactivated,
        ];
    }

    private function computeContentHash(array $data): string
    {
        $canonical = $this->canonicalize($data);

        unset(
            $canonical['recordedAt'],
            $canonical['updatedAt'],
            $canonical['createdAt'],
        );

        return hash(
            'sha256',
            json_encode(
                $canonical,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function fetchTvtFeed(string $url): array
    {
        $response = $this->httpClient->request(
            'GET',
            $url,
            [
                'timeout' => 60,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'WazeBR-Symfony/1.0',
                ],
            ],
        );

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'O feed TVT retornou HTTP %d.',
                $response->getStatusCode(),
            ));
        }

        try {
            $payload = $response->toArray();
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'O feed TVT retornou JSON inválido.',
                previous: $exception,
            );
        }

        if (!isset($payload['routes']) || !is_array($payload['routes'])) {
            throw new \RuntimeException(
                'O JSON TVT não possui a chave routes.',
            );
        }

        return $payload;
    }

    /**
     * Gera um identificador estável para subrotas que vêm sem "id" no payload.
     *
     * @param array<string, mixed> $subRouteData
     */
    private function computeSubRouteId(array $subRouteData): string
    {
        if (!empty($subRouteData['id'])) {
            return trim((string) $subRouteData['id']);
        }

        $line = is_array($subRouteData['line'] ?? null)
            ? $subRouteData['line']
            : [];

        $first = $line[0] ?? null;
        $last  = $line !== [] ? $line[array_key_last($line)] : null;

        $signature = [
            'fromName'     => (string) ($subRouteData['fromName']     ?? ''),
            'toName'       => (string) ($subRouteData['toName']       ?? ''),
            'length'       => (int)    ($subRouteData['length']       ?? 0),
            'historicTime' => (int)    ($subRouteData['historicTime'] ?? 0),
            'first'        => $first,
            'last'         => $last,
        ];

        $hash = hash(
            'sha256',
            json_encode(
                $signature,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );

        return 'gen_' . substr($hash, 0, 60);
    }

    private function resetStaleConnection(): void
{
    $connection = $this->entityManager->getConnection();

    try {
        $connection->executeQuery('SELECT 1');
    } catch (\Throwable) {
        $connection->close();
    }
}
}
