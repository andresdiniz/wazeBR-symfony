<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\PartnerApiLink;
use App\Entity\WazeAlert;
use App\Entity\WazeJam;
use App\Repository\PartnerApiLinkRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeJamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WazeFeedSynchronizer
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly PartnerApiLinkRepository $apiLinkRepository,
        private readonly WazeAlertRepository $alertRepository,
        private readonly WazeJamRepository $jamRepository,
    ) {
    }

    /**
     * @return array{
     *     alertsCreated: int,
     *     alertsUpdated: int,
     *     alertsReactivated: int,
     *     alertsDeactivated: int,
     *     jamsCreated: int,
     *     jamsUpdated: int,
     *     jamsReactivated: int,
     *     jamsDeactivated: int
     * }
     */
    public function synchronize(
        Partner $partner,
        bool $dryRun = false,
    ): array {
        $links = $this->apiLinkRepository
            ->findActiveByPartner($partner);

        $result = [
            'alertsCreated' => 0,
            'alertsUpdated' => 0,
            'alertsReactivated' => 0,
            'alertsDeactivated' => 0,
            'jamsCreated' => 0,
            'jamsUpdated' => 0,
            'jamsReactivated' => 0,
            'jamsDeactivated' => 0,
        ];

        foreach ($links as $link) {
            $type = mb_strtoupper(
                trim((string) $link->getType()),
            );

            if (!in_array($type, ['ALERTS', 'JAMS'], true)) {
                continue;
            }

            $payload = $this->requestJson(
                (string) $link->getUrl(),
            );

            if ($type === 'ALERTS') {
                $alerts = $payload['alerts'] ?? [];
                $jams = $payload['jams'] ?? [];
            } else {
                $alerts = [];
                $jams = $payload['jams'] ?? [];
            }

            if (!is_array($alerts) || !is_array($jams)) {
                throw new \RuntimeException(
                    'O JSON Waze possui alerts ou jams inválidos.',
                );
            }

            $alertResult = $this->synchronizeAlerts(
                $partner,
                $alerts,
                $dryRun,
            );

            $jamResult = $this->synchronizeJams(
                $partner,
                $jams,
                $dryRun,
            );

            foreach ($alertResult as $key => $value) {
                $result[$key] += $value;
            }

            foreach ($jamResult as $key => $value) {
                $result[$key] += $value;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $url): array
    {
        if (trim($url) === '') {
            throw new \RuntimeException(
                'O link Waze está vazio.',
            );
        }

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
                'O Waze retornou HTTP %d.',
                $response->getStatusCode(),
            ));
        }

        try {
            $payload = $response->toArray();
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'A resposta do Waze não é um JSON válido.',
                previous: $exception,
            );
        }

        if (!is_array($payload)) {
            throw new \RuntimeException(
                'A resposta do Waze não possui estrutura válida.',
            );
        }

        return $payload;
    }

    /**
     * @param array<int, mixed> $alerts
     *
     * @return array{
     *     alertsCreated: int,
     *     alertsUpdated: int,
     *     alertsReactivated: int,
     *     alertsDeactivated: int
     * }
     */
    private function synchronizeAlerts(
        Partner $partner,
        array $alerts,
        bool $dryRun,
    ): array {
        $now = new \DateTimeImmutable();

        $created = 0;
        $updated = 0;
        $reactivated = 0;
        $currentUuids = [];

        foreach ($alerts as $data) {
            if (!is_array($data)) {
                continue;
            }

            $uuid = trim((string) (
                $data['uuid']
                ?? $data['id']
                ?? ''
            ));

            if ($uuid === '') {
                continue;
            }

            $currentUuids[] = $uuid;

            if ($dryRun) {
                continue;
            }

            $alert = $this->alertRepository
                ->findOneByPartnerAndUuid(
                    $partner,
                    $uuid,
                );

            if ($alert === null) {
                $alert = new WazeAlert();

                $alert
                    ->setPartner($partner)
                    ->setUuid($uuid);

                $this->entityManager->persist($alert);
                $created++;
            } else {
                $updated++;

                if (!$alert->isActive()) {
                    $reactivated++;
                }
            }

            $location = is_array($data['location'] ?? null)
                ? $data['location']
                : [];

            $alert
                ->setType((string) (
                    $data['type'] ?? 'HAZARD'
                ))
                ->setSubtype(
                    $data['subtype'] ?? null,
                )
                ->setPubMillis((int) (
                    $data['pubMillis'] ?? 0
                ))
                ->setReportByMunicipalityUser(
                    filter_var(
                        $data['reportByMunicipalityUser']
                        ?? false,
                        FILTER_VALIDATE_BOOLEAN,
                    ),
                )
                ->setReportRating((int) (
                    $data['reportRating'] ?? 0
                ))
                ->setConfidence((int) (
                    $data['confidence'] ?? 0
                ))
                ->setReliability((int) (
                    $data['reliability'] ?? 0
                ))
                ->setLongitude((float) (
                    $location['x'] ?? 0
                ))
                ->setLatitude((float) (
                    $location['y'] ?? 0
                ))
                ->setStreet($data['street'] ?? null)
                ->setCity($data['city'] ?? null)
                ->setCountry((string) (
                    $data['country'] ?? 'BR'
                ))
                ->setRoadType((int) (
                    $data['roadType'] ?? 0
                ))
                ->setReportDescription(
                    $data['reportDescription'] ?? null,
                )
                ->setNThumbsUp((int) (
                    $data['nThumbsUp'] ?? 0
                ))
                ->setMagvar((int) (
                    $data['magvar'] ?? 0
                ))
                ->setIsActive(true)
                ->setLastSeenAt($now);

            if ($alert->getCollectedAt() === null) {
                $alert->setCollectedAt($now);
            }
        }

        $currentUuids = array_values(
            array_unique($currentUuids),
        );

        $deactivated = 0;

        if (!$dryRun && $currentUuids !== []) {
            $deactivated = $this->alertRepository
                ->deactivateMissingForPartner(
                    $partner,
                    $currentUuids,
                    $now,
                );
        }

        return [
            'alertsCreated' => $created,
            'alertsUpdated' => $updated,
            'alertsReactivated' => $reactivated,
            'alertsDeactivated' => $deactivated,
        ];
    }

    /**
     * @param array<int, mixed> $jams
     *
     * @return array{
     *     jamsCreated: int,
     *     jamsUpdated: int,
     *     jamsReactivated: int,
     *     jamsDeactivated: int
     * }
     */
    private function synchronizeJams(
        Partner $partner,
        array $jams,
        bool $dryRun,
    ): array {
        $now = new \DateTimeImmutable();

        $created = 0;
        $updated = 0;
        $reactivated = 0;
        $currentUuids = [];

        foreach ($jams as $data) {
            if (!is_array($data)) {
                continue;
            }

            $uuid = trim((string) (
                $data['uuid']
                ?? $data['id']
                ?? ''
            ));

            if ($uuid === '') {
                continue;
            }

            $currentUuids[] = $uuid;

            if ($dryRun) {
                continue;
            }

            $jam = $this->jamRepository
                ->findOneByPartnerAndUuid(
                    $partner,
                    $uuid,
                );

            if ($jam === null) {
                $jam = new WazeJam();

                $jam
                    ->setPartner($partner)
                    ->setUuid($uuid);

                $this->entityManager->persist($jam);
                $created++;
            } else {
                $updated++;

                if (!$jam->isActive()) {
                    $reactivated++;
                }
            }

            $jam
                ->setJamId((int) (
                    $data['id'] ?? 0
                ))
                ->setLine(
                    is_array($data['line'] ?? null)
                        ? $data['line']
                        : [],
                )
                ->setSpeed((float) (
                    $data['speed'] ?? 0
                ))
                ->setSpeedKmh((float) (
                    $data['speedKMH'] ?? 0
                ))
                ->setLength((int) (
                    $data['length'] ?? 0
                ))
                ->setDelay((int) (
                    $data['delay'] ?? -1
                ))
                ->setLevel((int) (
                    $data['level'] ?? 0
                ))
                ->setPubMillis((int) (
                    $data['pubMillis'] ?? 0
                ))
                ->setTurnType((string) (
                    $data['turnType'] ?? 'NONE'
                ))
                ->setBlockingAlertUuid(
                    $data['blockingAlertUuid']
                    ?? null,
                )
                ->setSegments(
                    is_array($data['segments'] ?? null)
                        ? $data['segments']
                        : [],
                )
                ->setStreet($data['street'] ?? null)
                ->setCity($data['city'] ?? null)
                ->setCountry((string) (
                    $data['country'] ?? 'BR'
                ))
                ->setRoadType((int) (
                    $data['roadType'] ?? 0
                ))
                ->setEndNode($data['endNode'] ?? null)
                ->setIsActive(true)
                ->setLastSeenAt($now);

            if ($jam->getCollectedAt() === null) {
                $jam->setCollectedAt($now);
            }
        }

        $currentUuids = array_values(
            array_unique($currentUuids),
        );

        $deactivated = 0;

        if (!$dryRun && $currentUuids !== []) {
            $deactivated = $this->jamRepository
                ->deactivateMissingForPartner(
                    $partner,
                    $currentUuids,
                    $now,
                );
        }

        return [
            'jamsCreated' => $created,
            'jamsUpdated' => $updated,
            'jamsReactivated' => $reactivated,
            'jamsDeactivated' => $deactivated,
        ];
    }
}
