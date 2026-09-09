<?php

namespace App\Service;

use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use App\Entity\WazeTrafficJam;
use App\Repository\WazeTrafficJamRepository;
use Doctrine\ORM\EntityManagerInterface;

class WazeJamSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WazeTrafficJamRepository $jamRepository
    ) {}

    public function upsert(WazeFeed $feed, WazeFeedCollection $collection, array $data): WazeTrafficJam
    {
        $partner = $feed->getPartner();
        $externalId = isset($data['id']) ? (int)$data['id'] : null;

        // Regra 1: ID externo (jams têm ID numérico estável)
        if ($externalId) {
            $existing = $this->jamRepository->findOneByExternalId(
                $partner->getId(),
                $feed->getId(),
                $externalId
            );
            if ($existing) {
                $this->updateJam($existing, $data, $collection);
                return $existing;
            }
        }

        // Regra 2: dedupKey por geometria
        $dedupKey = $this->calculateDedupKey($feed, $data);
        $existing = $this->jamRepository->findOneByDedupKey($partner->getId(), $dedupKey);
        if ($existing) {
            $this->updateJam($existing, $data, $collection);
            return $existing;
        }

        // Criar novo
        $jam = new WazeTrafficJam();
        $jam->setPartner($partner);
        $jam->setWazeFeed($feed);
        $jam->setExternalId($externalId);
        $jam->setExternalUuid($data['uuid'] ?? null);
        $jam->setDedupKey($dedupKey);
        $jam->setFirstSeenAt(new \DateTime());
        $this->populateJam($jam, $data);
        $jam->setLastSeenAt(new \DateTime());
        $jam->setLastSeenCollection($collection);
        $jam->setIsActive(true);

        $this->em->persist($jam);
        $this->em->flush();

        return $jam;
    }

    private function updateJam(WazeTrafficJam $jam, array $data, WazeFeedCollection $collection): void
    {
        $this->populateJam($jam, $data);
        $jam->setLastSeenAt(new \DateTime());
        $jam->setLastSeenCollection($collection);
        $jam->setIsActive(true);
        $jam->setMissingSinceAt(null);
        $jam->setDeactivatedAt(null);
        $this->em->flush();
    }

    private function populateJam(WazeTrafficJam $jam, array $data): void
    {
        $line = $data['line'] ?? [];
        $startPoint = $line[0] ?? [];
        $endPoint = !empty($line) ? $line[count($line) - 1] : [];

        $geometry = $line;
        $geometryHash = !empty($geometry) ? hash('sha256', json_encode($geometry)) : '';

        $jam->setStreet($data['street'] ?? null);
        $jam->setStreetNormalized($this->normalizeStreet($data['street'] ?? null));
        $jam->setCity($data['city'] ?? null);
        $jam->setCountry($data['country'] ?? null);
        $jam->setRoadType($data['roadType'] ?? null);
        $jam->setStartLatitude(isset($startPoint['y']) ? (float)$startPoint['y'] : null);
        $jam->setStartLongitude(isset($startPoint['x']) ? (float)$startPoint['x'] : null);
        $jam->setEndLatitude(isset($endPoint['y']) ? (float)$endPoint['y'] : null);
        $jam->setEndLongitude(isset($endPoint['x']) ? (float)$endPoint['x'] : null);
        $jam->setGeometry($geometry ?: null);
        $jam->setGeometryHash($geometryHash ?: null);
        $jam->setLengthMeters(isset($data['length']) ? (int)$data['length'] : null);
        $jam->setSpeedKmh(isset($data['speedKMH']) ? (string)(float)$data['speedKMH'] : null);
        $jam->setSpeedMps(isset($data['speed']) ? (string)(float)$data['speed'] : null);
        $jam->setDelaySeconds(isset($data['delay']) ? (int)$data['delay'] : null);
        $jam->setLevel($data['level'] ?? null);
        $jam->setTurnType($data['turnType'] ?? null);
        $jam->setBlockingAlertUuid($data['blockingAlertUuid'] ?? null);
        $jam->setRawPayload($data);

        if (isset($data['pubMillis'])) {
            $publishedAt = new \DateTime();
            $publishedAt->setTimestamp((int)($data['pubMillis'] / 1000));
            $jam->setPublishedAt($publishedAt);
        }
    }

    private function calculateDedupKey(WazeFeed $feed, array $data): string
    {
        $street = $this->normalizeStreet($data['street'] ?? '');
        $line = $data['line'] ?? [];
        $geometryHash = !empty($line) ? hash('sha256', json_encode($line)) : 'empty';

        $raw = sprintf(
            '%d|%d|%s|%s',
            $feed->getPartner()->getId(),
            $feed->getId(),
            $street,
            $geometryHash
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
