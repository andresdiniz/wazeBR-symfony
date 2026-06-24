<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WazeApiService
{
    public function __construct(
        private readonly HttpClientInterface    $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface        $wazeLogger,
        private readonly string                 $apiUrl,
        private readonly string                 $apiKey,
        private readonly string                 $bbox,
    ) {}

    public function collectAlerts(): array
    {
        $this->wazeLogger->info('Coletando alertas do Waze', ['bbox' => $this->bbox]);

        $response = $this->httpClient->request('GET', $this->apiUrl, [
            'query' => [
                'tk'   => $this->apiKey,
                'bbox' => $this->bbox,
                'ma'   => 100,
                'mj'   => 0,
                'ml'   => 0,
            ],
        ]);

        $data   = $response->toArray();
        $alerts = $data['alerts'] ?? [];
        $saved  = 0;

        $existingIds = $this->getExistingWazeIds(WazeAlert::class);

        foreach ($alerts as $alertData) {
            $wazeId = $alertData['uuid'] ?? '';
            if (!$wazeId || in_array($wazeId, $existingIds, true)) {
                continue;
            }

            $alert = (new WazeAlert())
                ->setWazeId($wazeId)
                ->setType($alertData['type'] ?? 'UNKNOWN')
                ->setSubtype($alertData['subtype'] ?? null)
                ->setLatitude((float) ($alertData['location']['y'] ?? 0))
                ->setLongitude((float) ($alertData['location']['x'] ?? 0))
                ->setStreet($alertData['street'] ?? null)
                ->setCity($alertData['city'] ?? null)
                ->setCountry($alertData['country'] ?? null)
                ->setReliability($alertData['reliability'] ?? null)
                ->setConfidence($alertData['confidence'] ?? null)
                ->setReportRating($alertData['reportRating'] ?? null)
                ->setPubMillis((int) ($alertData['pubMillis'] ?? 0));

            $this->entityManager->persist($alert);
            $saved++;
        }

        $this->entityManager->flush();

        $this->wazeLogger->info('Alertas coletados', ['total_api' => count($alerts), 'novos' => $saved]);
        return ['total' => count($alerts), 'saved' => $saved];
    }

    public function collectTrafficJams(): array
    {
        $this->wazeLogger->info('Coletando congestionamentos do Waze', ['bbox' => $this->bbox]);

        $response = $this->httpClient->request('GET', $this->apiUrl, [
            'query' => [
                'tk'   => $this->apiKey,
                'bbox' => $this->bbox,
                'ma'   => 0,
                'mj'   => 100,
                'ml'   => 0,
            ],
        ]);

        $data  = $response->toArray();
        $jams  = $data['jams'] ?? [];
        $saved = 0;

        $existingIds = $this->getExistingWazeIds(WazeTrafficJam::class);

        foreach ($jams as $jamData) {
            $wazeId = $jamData['uuid'] ?? '';
            if (!$wazeId || in_array($wazeId, $existingIds, true)) {
                continue;
            }

            $jam = (new WazeTrafficJam())
                ->setWazeId($wazeId)
                ->setStreet($jamData['street'] ?? null)
                ->setCity($jamData['city'] ?? null)
                ->setLevel($jamData['level'] ?? null)
                ->setSpeedKmh(isset($jamData['speedKMH']) ? (float) $jamData['speedKMH'] : null)
                ->setLength(isset($jamData['length']) ? (float) $jamData['length'] : null)
                ->setDelay($jamData['delay'] ?? null)
                ->setLine($jamData['line'] ?? null)
                ->setPubMillis((int) ($jamData['pubMillis'] ?? 0));

            $this->entityManager->persist($jam);
            $saved++;
        }

        $this->entityManager->flush();

        $this->wazeLogger->info('Congestionamentos coletados', ['total_api' => count($jams), 'novos' => $saved]);
        return ['total' => count($jams), 'saved' => $saved];
    }

    private function getExistingWazeIds(string $entityClass): array
    {
        return array_column(
            $this->entityManager->createQuery("SELECT e.wazeId FROM {$entityClass} e WHERE e.pubMillis > :since")
                ->setParameter('since', (int) ((time() - 3600) * 1000))
                ->getArrayResult(),
            'wazeId'
        );
    }

    public function generateJson(string $outputPath): void
    {
        $alerts = $this->entityManager->getRepository(WazeAlert::class)->findRecentAlerts(2);
        $jams   = $this->entityManager->getRepository(WazeTrafficJam::class)->findRecentJams(2);

        $data = [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'alerts'       => array_map(fn($a) => $this->alertToArray($a), $alerts),
            'jams'         => array_map(fn($j) => $this->jamToArray($j), $jams),
        ];

        @mkdir(dirname($outputPath), 0755, true);
        file_put_contents($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->wazeLogger->info('JSON gerado', ['path' => $outputPath]);
    }

    public function generateXml(string $outputPath): void
    {
        $alerts = $this->entityManager->getRepository(WazeAlert::class)->findRecentAlerts(2);

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><feed/>');
        $xml->addAttribute('generated', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));

        foreach ($alerts as $alert) {
            $item = $xml->addChild('alert');
            $item->addChild('id', htmlspecialchars($alert->getWazeId()));
            $item->addChild('type', htmlspecialchars($alert->getType()));
            $item->addChild('subtype', htmlspecialchars($alert->getSubtype() ?? ''));
            $item->addChild('street', htmlspecialchars($alert->getStreet() ?? ''));
            $item->addChild('city', htmlspecialchars($alert->getCity() ?? ''));
            $loc = $item->addChild('location');
            $loc->addChild('lat', (string) $alert->getLatitude());
            $loc->addChild('lng', (string) $alert->getLongitude());
        }

        @mkdir(dirname($outputPath), 0755, true);
        $xml->asXML($outputPath);
        $this->wazeLogger->info('XML gerado', ['path' => $outputPath]);
    }

    private function alertToArray(WazeAlert $a): array
    {
        return [
            'id'          => $a->getWazeId(),
            'type'        => $a->getType(),
            'subtype'     => $a->getSubtype(),
            'street'      => $a->getStreet(),
            'city'        => $a->getCity(),
            'lat'         => $a->getLatitude(),
            'lng'         => $a->getLongitude(),
            'reliability' => $a->getReliability(),
            'pub_millis'  => $a->getPubMillis(),
        ];
    }

    private function jamToArray(WazeTrafficJam $j): array
    {
        return [
            'id'        => $j->getWazeId(),
            'street'    => $j->getStreet(),
            'city'      => $j->getCity(),
            'level'     => $j->getLevel(),
            'speed_kmh' => $j->getSpeedKmh(),
            'length'    => $j->getLength(),
            'delay'     => $j->getDelay(),
        ];
    }
}
