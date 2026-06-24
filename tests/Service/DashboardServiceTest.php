<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\ActivityLogRepository;
use App\Repository\CemadenDataRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Service\DashboardService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DashboardServiceTest extends TestCase
{
    private WazeAlertRepository&MockObject $alertRepo;
    private WazeTrafficJamRepository&MockObject $jamRepo;
    private CemadenDataRepository&MockObject $cemadenRepo;
    private ActivityLogRepository&MockObject $logRepo;
    private DashboardService $service;

    protected function setUp(): void
    {
        $this->alertRepo   = $this->createMock(WazeAlertRepository::class);
        $this->jamRepo     = $this->createMock(WazeTrafficJamRepository::class);
        $this->cemadenRepo = $this->createMock(CemadenDataRepository::class);
        $this->logRepo     = $this->createMock(ActivityLogRepository::class);

        $this->service = new DashboardService(
            alertRepository: $this->alertRepo,
            trafficJamRepository: $this->jamRepo,
            cemadenRepository: $this->cemadenRepo,
            activityLogRepository: $this->logRepo,
        );
    }

    public function testGetKpisReturnsExpectedStructure(): void
    {
        $this->alertRepo->method('countLast24h')->willReturn(42);
        $this->alertRepo->method('countByType')->willReturn(['ACCIDENT' => 10, 'HAZARD' => 15, 'JAM' => 17]);
        $this->jamRepo->method('countLast24h')->willReturn(18);
        $this->jamRepo->method('countCritical')->willReturn(5);
        $this->cemadenRepo->method('countHighRisk')->willReturn(3);

        $kpis = $this->service->getKpis();

        $this->assertArrayHasKey('alerts_24h', $kpis);
        $this->assertArrayHasKey('jams_24h', $kpis);
        $this->assertArrayHasKey('jams_critical', $kpis);
        $this->assertArrayHasKey('cemaden_high_risk', $kpis);
        $this->assertArrayHasKey('alerts_by_type', $kpis);

        $this->assertSame(42, $kpis['alerts_24h']);
        $this->assertSame(18, $kpis['jams_24h']);
        $this->assertSame(5,  $kpis['jams_critical']);
        $this->assertSame(3,  $kpis['cemaden_high_risk']);
    }

    public function testGetKpisWithZeroValues(): void
    {
        $this->alertRepo->method('countLast24h')->willReturn(0);
        $this->alertRepo->method('countByType')->willReturn([]);
        $this->jamRepo->method('countLast24h')->willReturn(0);
        $this->jamRepo->method('countCritical')->willReturn(0);
        $this->cemadenRepo->method('countHighRisk')->willReturn(0);

        $kpis = $this->service->getKpis();

        $this->assertSame(0, $kpis['alerts_24h']);
        $this->assertSame(0, $kpis['jams_24h']);
        $this->assertSame([], $kpis['alerts_by_type']);
    }
}
