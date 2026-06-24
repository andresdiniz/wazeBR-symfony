<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CemadenData;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CemadenService
{
    public function __construct(
        private readonly HttpClientInterface    $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface        $cemadenLogger,
        private readonly string                 $apiUrl,
        private readonly string                 $apiKey,
    ) {}

    public function collectData(string $state = 'MG'): array
    {
        $this->cemadenLogger->info('Coletando dados CEMADEN', ['state' => $state]);

        $response = $this->httpClient->request('GET', $this->apiUrl, [
            'query' => [
                'key'   => $this->apiKey,
                'state' => $state,
            ],
        ]);

        $data    = $response->toArray();
        $records = $data['body'] ?? [];
        $saved   = 0;

        foreach ($records as $record) {
            $station = (new CemadenData())
                ->setStationCode($record['codEstacao'] ?? 'N/A')
                ->setStationName($record['nomeEstacao'] ?? 'N/A')
                ->setMunicipality($record['municipio'] ?? 'N/A')
                ->setState($state)
                ->setLatitude((float) ($record['latitude'] ?? 0))
                ->setLongitude((float) ($record['longitude'] ?? 0))
                ->setAccumulatedRain(isset($record['valorMedida']) ? (float) $record['valorMedida'] : null)
                ->setAlertLevel($record['statusAlerta'] ?? null)
                ->setMeasuredAt(new \DateTimeImmutable($record['dataHora'] ?? 'now'));

            $this->entityManager->persist($station);
            $saved++;
        }

        $this->entityManager->flush();

        $this->cemadenLogger->info('Dados CEMADEN coletados', ['total_api' => count($records), 'salvos' => $saved]);
        return ['total' => count($records), 'saved' => $saved];
    }

    public function getActiveAlerts(): array
    {
        return $this->entityManager->getRepository(CemadenData::class)->findActiveAlerts();
    }
}
