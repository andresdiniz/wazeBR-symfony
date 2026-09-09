<?php

namespace App\Service;

use App\Entity\WazeAlert;
use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use App\Repository\WazeAlertRepository;
use Doctrine\ORM\EntityManagerInterface;

class WazeAlertSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WazeAlertRepository $alertRepository,
        private readonly GeohashService $geohashService
    ) {}

    public function upsert(WazeFeed $feed, WazeFeedCollection $collection, array $data): WazeAlert
    {
        $partner      = $feed->getPartner();
        $externalUuid = $data['uuid'] ?? null;

        // Regra 1: UUID externo
        if ($externalUuid) {
            $existing = $this->alertRepository->findOneByExternalUuid(
                $partner->getId(),
                $feed->getId(),
                $externalUuid
            );
            if ($existing) {
                $this->updateAlert($existing, $data, $collection);
                return $existing;
            }
        }

        // Regra 2: dedupKey
        $dedupKey = $this->calculateDedupKey($feed, $data);
        $existing  = $this->alertRepository->findOneByDedupKey($partner->getId(), $dedupKey);
        if ($existing) {
            $this->updateAlert($existing, $data, $collection);
            return $existing;
        }

        // Criar novo
        $alert = new WazeAlert();
        $alert->setPartner($partner);
        $alert->setWazeFeed($feed);
        $alert->setExternalUuid($externalUuid);
        $alert->setDedupKey($dedupKey);
        $alert->setFirstSeenAt(new \DateTime());
        $this->populateAlert($alert, $data);
        $alert->setLastSeenAt(new \DateTime());
        $alert->setLastSeenCollection($collection);
        $alert->setIsActive(true);

        $this->em->persist($alert);
        $this->em->flush();

        return $alert;
    }

    private function updateAlert(WazeAlert $alert, array $data, WazeFeedCollection $collection): void
    {
        $this->populateAlert($alert, $data);
        $alert->setLastSeenAt(new \DateTime());
        $alert->setLastSeenCollection($collection);
        $alert->setIsActive(true);
        $alert->setMissingSinceAt(null);
        $alert->setDeactivatedAt(null);
        $this->em->flush();
    }

    private function populateAlert(WazeAlert $alert, array $data): void
    {
        $lat    = (float)($data['location']['y'] ?? 0.0);
        $lon    = (float)($data['location']['x'] ?? 0.0);
        $street = $data['street'] ?? null;

        $alert->setType($data['type'] ?? '');
        $alert->setSubtype($data['subtype'] ?? null);
        $alert->setLatitude((string)$lat);
        $alert->setLongitude((string)$lon);
        $alert->setGeohash($this->geohashService->encode($lat, $lon, 8));
        $alert->setStreet($street);
        $alert->setStreetNormalized($this->normalizeStreet($street));
        $alert->setCity($data['city'] ?? null);
        $alert->setCountry($data['country'] ?? null);
        $alert->setRoadType($data['roadType'] ?? null);
        $alert->setDescription($data['reportDescription'] ?? null);
        $alert->setConfidence($data['confidence'] ?? null);
        $alert->setReliability($data['reliability'] ?? null);
        $alert->setReportRating($data['reportRating'] ?? null);
        $alert->setThumbsUp($data['nThumbsUp'] ?? null);
        $alert->setMagvar($data['magvar'] ?? null);
        $alert->setRawPayload($data);

        if (isset($data['pubMillis'])) {
            $reportedAt = new \DateTime();
            $reportedAt->setTimestamp((int)($data['pubMillis'] / 1000));
            $alert->setReportedAt($reportedAt);
        }
    }

    private function calculateDedupKey(WazeFeed $feed, array $data): string
    {
        $lat     = (float)($data['location']['y'] ?? 0.0);
        $lon     = (float)($data['location']['x'] ?? 0.0);
        $street  = $this->normalizeStreet($data['street'] ?? '');
        $geohash = $this->geohashService->encode($lat, $lon, 6);
        $pubMillisRounded = isset($data['pubMillis'])
            ? (int)((int)$data['pubMillis'] / 300000) * 300000
            : 0;

        $raw = sprintf(
            '%d|%d|%s|%s|%s|%s|%d',
            $feed->getPartner()->getId(),
            $feed->getId(),
            $data['type'] ?? '',
            $data['subtype'] ?? '',
            $street,
            $geohash,
            $pubMillisRounded
        );

        return hash('sha256', $raw);
    }

    private function normalizeStreet(?string $street): string
    {
        if (!$street) {
            return '';
        }
        $street = mb_strtoupper($street);
        $street = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $street) ?: $street;
        $street = (string)preg_replace('/[^A-Z0-9 ]/', '', $street);
        $street = (string)preg_replace('/\s+/', ' ', $street);
        return trim($street);
    }
}
