<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
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
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WazeTvtSynchronizer
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly PartnerApiLinkRepository $apiLinkRepository,
        private readonly WazeTvtRouteRepository $routeRepository,
        private readonly WazeTvtSubRouteRepository $subRouteRepository,
        private readonly WazeTvtIrregularityRepository $irregularityRepository,
        private readonly WazeTvtRouteSnapshotRepository $snapshotRepository,
        private readonly WazeTvtUserOnJamRepository $userOnJamRepository,
    ) {
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
    public function synchronize(
        Partner $partner,
        bool $dryRun = false,
    ): array {
        $link = $this->apiLinkRepository
            ->findOneBy([
                'partner' => $partner,
                'type' => 'TVT',
                'active' => true,
            ]);

        if ($link === null) {
            throw new \RuntimeException(
                'Nenhum link TVT ativo foi encontrado para o partner.',
            );
        }

        $payload = $this->requestJson(
            (string) $link->getUrl(),
        );

        return $this->processPayload(
            $payload,
            $partner,
            $dryRun,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $url): array
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

        if (!is_array($payload)) {
            throw new \RuntimeException(
                'O payload TVT não é um objeto JSON válido.',
            );
        }

        if (
            !isset($payload['routes'])
            || !is_array($payload['routes'])
        ) {
            throw new \RuntimeException(
                'O payload TVT não possui a chave routes.',
            );
        }

        return $payload;
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
    private function processPayload(
        array $payload,
        Partner $partner,
        bool $dryRun,
    ): array {
        $now = new \DateTimeImmutable(
            'now',
            new \DateTimeZone('UTC'),
        );

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

        $routes = $payload['routes'];

        /*
         * O JSON real usa usersOnJams.
         */
        if (
            isset($payload['usersOnJams'])
            && is_array($payload['usersOnJams'])
        ) {
            $result['usersOnJamCreated'] +=
                $this->persistUsersOnJam(
                    $partner,
                    $payload['usersOnJams'],
                    null,
                    $now,
                    $dryRun,
                );
        }

        $currentRouteIds = [];

        foreach ($routes as $routeData) {
            if (!is_array($routeData)) {
                continue;
            }

            $routeId = trim(
                (string) ($routeData['id'] ?? ''),
            );

            if ($routeId === '') {
                continue;
            }

            $currentRouteIds[] = $routeId;

            $routeResult = $this->upsertRoute(
                $partner,
                $routeId,
                $routeData,
                $now,
                $dryRun,
            );

            /** @var WazeTvtRoute $route */
            $route = $routeResult['entity'];

            $result['routesCreated'] += $routeResult['created'];
            $result['routesReactivated'] += $routeResult['reactivated'];

            if (
                $this->persistSnapshot(
                    $partner,
                    $route,
                    $routeId,
                    $routeData,
                    $now,
                    $dryRun,
                )
            ) {
                $result['snapshotsCreated']++;
            }

            if (
                isset($routeData['usersOnJams'])
                && is_array($routeData['usersOnJams'])
            ) {
                $result['usersOnJamCreated'] +=
                    $this->persistUsersOnJam(
                        $partner,
                        $routeData['usersOnJams'],
                        $route,
                        $now,
                        $dryRun,
                    );
            }

            $subRouteIds = [];

            foreach (
                ($routeData['subRoutes'] ?? []) as $subRouteData
            ) {
                if (!is_array($subRouteData)) {
                    continue;
                }

                $subRouteId = trim(
                    (string) ($subRouteData['id'] ?? ''),
                );

                if ($subRouteId === '') {
                    continue;
                }

                $subRouteIds[] = $subRouteId;

                $subRouteResult = $this->upsertSubRoute(
                    $partner,
                    $route,
                    $routeId,
                    $subRouteId,
                    $subRouteData,
                    $now,
                    $dryRun,
                );

                /** @var WazeTvtSubRoute $subRoute */
                $subRoute = $subRouteResult['entity'];

                $result['subRoutesCreated'] +=
                    $subRouteResult['created'];

                $result['subRoutesReactivated'] +=
                    $subRouteResult['reactivated'];

                $hashes = [];

                foreach (
                    ($subRouteData['irregularities'] ?? []) as $data
                ) {
                    if (!is_array($data)) {
                        continue;
                    }

                    $irregularityResult =
                        $this->upsertIrregularity(
                            $partner,
                            $route,
                            $subRoute,
                            $routeId,
                            $subRouteId,
                            $data,
                            $now,
                            $dryRun,
                        );

                    $hashes[] = $irregularityResult['hash'];

                    $result['irregularitiesCreated'] +=
                        $irregularityResult['created'];

                    $result['irregularitiesReactivated'] +=
                        $irregularityResult['reactivated'];
                }

                if (!$dryRun && $hashes !== []) {
                    $result['irregularitiesDeactivated'] +=
                        $this->irregularityRepository
                            ->deactivateMissingForScope(
                                $partner,
                                $route,
                                $subRoute,
                                array_values(array_unique($hashes)),
                                $now,
                            );
                }
            }

            if (!$dryRun && $subRouteIds !== []) {
                $result['subRoutesDeactivated'] +=
                    $this->subRouteRepository
                        ->deactivateMissingForRoute(
                            $partner,
                            $route,
                            array_values(array_unique($subRouteIds)),
                            $now,
                        );
            }

            $routeHashes = [];

            foreach (
                ($routeData['irregularities'] ?? []) as $data
            ) {
                if (!is_array($data)) {
                    continue;
                }

                $irregularityResult =
                    $this->upsertIrregularity(
                        $partner,
                        $route,
                        null,
                        $routeId,
                        null,
                        $data,
                        $now,
                        $dryRun,
                    );

                $routeHashes[] = $irregularityResult['hash'];

                $result['irregularitiesCreated'] +=
                    $irregularityResult['created'];

                $result['irregularitiesReactivated'] +=
                    $irregularityResult['reactivated'];
            }

            if (!$dryRun && $routeHashes !== []) {
                $result['irregularitiesDeactivated'] +=
                    $this->irregularityRepository
                        ->deactivateMissingForScope(
                            $partner,
                            $route,
                            null,
                            array_values(array_unique($routeHashes)),
                            $now,
                        );
            }
        }

        if (!$dryRun && $currentRouteIds !== []) {
            $result['routesDeactivated'] =
                $this->routeRepository
                    ->deactivateMissingForPartner(
                        $partner,
                        array_values(array_unique($currentRouteIds)),
                        $now,
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
        string $routeId,
        array $data,
        \DateTimeImmutable $now,
        bool $dryRun,
    ): array {
        $route = $this->routeRepository
            ->findOneByPartnerAndRouteId(
                $partner,
                $routeId,
            );

        $created = 0;
        $reactivated = 0;

        if ($route === null) {
            $route = new WazeTvtRoute();

            $route
                ->setPartner($partner)
                ->setRouteId($routeId);

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
            ->setLastSeenAt($now);

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
        string $routeId,
        string $subRouteId,
        array $data,
        \DateTimeImmutable $now,
        bool $dryRun,
    ): array {
        $subRoute = $this->subRouteRepository
            ->findOneByIdentity(
                $partner,
                $route,
                $subRouteId,
            );

        $created = 0;
        $reactivated = 0;

        if ($subRoute === null) {
            $subRoute = new WazeTvtSubRoute();

            $subRoute
                ->setPartner($partner)
                ->setRoute($route)
                ->setWazeRouteId($routeId)
                ->setSubRouteId($subRouteId);

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
            ->setLastSeenAt($now);

        return [
            'entity' => $subRoute,
            'created' => $created,
            'reactivated' => $reactivated,
        ];
    }

    private function persistSnapshot(
        Partner $partner,
        WazeTvtRoute $route,
        string $routeId,
        array $data,
        \DateTimeImmutable $now,
        bool $dryRun,
    ): bool {
        if ($dryRun) {
            return false;
        }

        $snapshot = new WazeTvtRouteSnapshot();

        $snapshot
            ->setPartner($partner)
            ->setRoute($route)
            ->setWazeRouteId($routeId)
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
            ->setRecordedAt($now);

        $this->entityManager->persist($snapshot);

        return true;
    }

    private function persistUsersOnJam(
        Partner $partner,
        array $data,
        ?WazeTvtRoute $route,
        \DateTimeImmutable $now,
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
            ->setRecordedAt($now);

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
        string $routeId,
        ?string $subRouteId,
        array $data,
        \DateTimeImmutable $now,
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

        $irregularity = $this->irregularityRepository
            ->findOneByContentHash(
                $partner,
                $route,
                $subRoute,
                $contentHash,
            );

        $created = 0;
        $reactivated = 0;

        if ($irregularity === null) {
            $irregularity = new WazeTvtIrregularity();

            $irregularity
                ->setPartner($partner)
                ->setRoute($route)
                ->setSubRoute($subRoute)
                ->setContentHash($contentHash);

            $this->entityManager->persist($irregularity);

            $created++;
        } elseif (!$irregularity->isActive()) {
            $reactivated++;
        }

        $irregularity
            ->setWazeRouteId($routeId)
            ->setWazeSubRouteId($subRouteId)
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
            ->setRecordedAt($now)
            ->setLastSeenAt($now)
            ->setUpdatedAt($now);

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

        if (
            !isset($payload['routes'])
            || !is_array($payload['routes'])
        ) {
            throw new \RuntimeException(
                'O JSON TVT não possui a chave routes.',
            );
        }

        return $payload;
    }
}
