<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CemadenData;
use App\Repository\CemadenDataRepository;
use App\Service\CemadenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CemadenServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private CemadenDataRepository&MockObject $repo;
    private LoggerInterface&MockObject $logger;
    private CemadenService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->repo       = $this->createMock(CemadenDataRepository::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->service = new CemadenService(
            httpClient: $this->httpClient,
            cemadenRepository: $this->repo,
            logger: $this->logger,
            apiUrl: 'https://api.cemaden.example',
            apiKey: 'test-cemaden-key',
        );
    }

    public function testFetchAndPersistSavesNewRecord(): void
    {
        $payload = [
            [
                'codEstacao'     => 'EST001',
                'nomEstacao'     => 'Estação Teste',
                'municipio'      => 'Belo Horizonte',
                'uf'             => 'MG',
                'latitude'       => -19.92,
                'longitude'      => -43.93,
                'valorMedida'    => 22.5,
                'nivelAlerta'    => 'AMARELO',
                'dataMedicao'    => '2026-06-23T22:00:00',
            ],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($payload);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->repo->expects($this->once())
            ->method('save');

        $result = $this->service->fetchAndPersist('MG');

        $this->assertSame(1, $result['saved']);
    }

    public function testGetLatestByStateReturnsCollection(): void
    {
        $data = [new CemadenData(), new CemadenData()];

        $this->repo->expects($this->once())
            ->method('findLatestByState')
            ->with('MG')
            ->willReturn($data);

        $result = $this->service->getLatestByState('MG');

        $this->assertCount(2, $result);
    }

    public function testGetHighRiskStationsFiltersCorrectly(): void
    {
        $red    = (new CemadenData())->setAlertLevel('VERMELHO');
        $orange = (new CemadenData())->setAlertLevel('LARANJA');
        $green  = (new CemadenData())->setAlertLevel('VERDE');

        $this->repo->expects($this->once())
            ->method('findHighRisk')
            ->willReturn([$red, $orange]);

        $result = $this->service->getHighRiskStations();

        $this->assertCount(2, $result);
        $this->assertNotContains($green, $result);
    }
}
