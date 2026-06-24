<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Service\WazeApiService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class WazeApiServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private WazeAlertRepository&MockObject $alertRepo;
    private WazeTrafficJamRepository&MockObject $jamRepo;
    private LoggerInterface&MockObject $logger;
    private WazeApiService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->alertRepo  = $this->createMock(WazeAlertRepository::class);
        $this->jamRepo    = $this->createMock(WazeTrafficJamRepository::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->service = new WazeApiService(
            httpClient: $this->httpClient,
            alertRepository: $this->alertRepo,
            trafficJamRepository: $this->jamRepo,
            logger: $this->logger,
            apiUrl: 'https://api.waze.example/alerts',
            apiKey: 'test-key',
            bbox: '-20.1,-44.1,-19.8,-43.8',
        );
    }

    public function testFetchAlertsReturnsAlerts(): void
    {
        $payload = [
            'alerts' => [
                [
                    'uuid'         => 'abc-123',
                    'type'         => 'ACCIDENT',
                    'subtype'      => null,
                    'location'     => ['x' => -43.93, 'y' => -19.92],
                    'street'       => 'Av. Afonso Pena',
                    'city'         => 'Belo Horizonte',
                    'country'      => 'BR',
                    'reliability'  => 8,
                    'confidence'   => 7,
                    'reportRating' => 4,
                    'pubMillis'    => 1_700_000_000_000,
                ],
            ],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($payload);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->alertRepo->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $this->alertRepo->expects($this->once())
            ->method('save');

        $result = $this->service->fetchAndPersistAlerts();

        $this->assertSame(1, $result['saved']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testFetchAlertsSkipsDuplicates(): void
    {
        $payload = [
            'alerts' => [
                [
                    'uuid'         => 'abc-123',
                    'type'         => 'ACCIDENT',
                    'subtype'      => null,
                    'location'     => ['x' => -43.93, 'y' => -19.92],
                    'street'       => 'Av. Afonso Pena',
                    'city'         => 'Belo Horizonte',
                    'country'      => 'BR',
                    'reliability'  => 8,
                    'confidence'   => 7,
                    'reportRating' => 4,
                    'pubMillis'    => 1_700_000_000_000,
                ],
            ],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($payload);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->alertRepo->expects($this->once())
            ->method('findOneBy')
            ->willReturn(new WazeAlert());

        $this->alertRepo->expects($this->never())->method('save');

        $result = $this->service->fetchAndPersistAlerts();

        $this->assertSame(0, $result['saved']);
        $this->assertSame(1, $result['skipped']);
    }

    public function testFetchTrafficJamsReturnsSaved(): void
    {
        $payload = [
            'jams' => [
                [
                    'uuid'    => 'jam-001',
                    'street'  => 'Av. Raja Gabaglia',
                    'city'    => 'Belo Horizonte',
                    'level'   => 3,
                    'speedKMH'=> 20.0,
                    'length'  => 800,
                    'delay'   => 120,
                    'line'    => [['x' => -43.9, 'y' => -19.9]],
                    'pubMillis' => 1_700_000_000_000,
                ],
            ],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($payload);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->jamRepo->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $this->jamRepo->expects($this->once())
            ->method('save');

        $result = $this->service->fetchAndPersistTrafficJams();

        $this->assertSame(1, $result['saved']);
    }
}
