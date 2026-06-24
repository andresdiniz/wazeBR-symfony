<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HealthCheckService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class HealthCheckServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private HttpClientInterface&MockObject $httpClient;
    private HealthCheckService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $this->service = new HealthCheckService(
            connection: $this->connection,
            httpClient: $this->httpClient,
            wazeApiUrl: 'https://api.waze.example',
            cemadenApiUrl: 'https://api.cemaden.example',
        );
    }

    public function testCheckAllReturnsOkWhenEverythingHealthy(): void
    {
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturn($response);

        $result = $this->service->checkAll();

        $this->assertSame('ok', $result['database']);
        $this->assertSame('ok', $result['waze_api']);
        $this->assertSame('ok', $result['cemaden_api']);
        $this->assertSame('ok', $result['status']);
    }

    public function testCheckAllReturnsDegradedOnDbFailure(): void
    {
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \Exception('Connection refused'));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->checkAll();

        $this->assertSame('error', $result['database']);
        $this->assertSame('degraded', $result['status']);
    }
}
