<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenDataRepository;
use App\Repository\MonitoredCityRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTvtRouteRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard', name: 'dashboard_')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext            $tenantContext,
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly CemadenDataRepository    $cemadenRepo,
        private readonly WazeTvtRouteRepository   $tvtRouteRepo,
        private readonly MonitoredCityRepository  $cityRepo,
        private readonly MonitoredLinkRepository  $linkRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $partner = $this->tenantContext->requirePartner();

        // ── KPIs básicos ──────────────────────────────────────────────────────
        $alertCount   = $this->alertRepo->countByPartner($partner);
        $jamCount     = $this->jamRepo->countByPartner($partner);
        $cemadenCount = $this->cemadenRepo->countByPartner($partner);
        $cityCount    = $this->cityRepo->countByPartner($partner);
        $linkCount    = $this->linkRepo->countByPartner($partner);
        $routeCount   = $this->tvtRouteRepo->countByPartner($partner);

        // ── KPIs derivados ───────────────────────────────────────────────────
        $alertsLast24h  = $this->alertRepo->countLast24hByPartner($partner);
        $jamsLast24h    = $this->jamRepo->countLast24hByPartner($partner);
        $avgJamSpeed    = $this->jamRepo->avgSpeedKmhByPartner($partner);
        $avgJamDelay    = $this->jamRepo->avgDelaySecsByPartner($partner);
        $totalJamLength = $this->jamRepo->totalLengthMByPartner($partner);

        // ── KPIs das últimas 3h (jams ativos) ───────────────────────────────
        $liveStats   = $this->jamRepo->avgStats($partner, 3);
        $liveJams    = $this->jamRepo->findLiveByPartner($partner, 3);
        $maxJamLevel = count($liveJams) > 0
            ? max(array_map(fn($j) => $j->getLevel() ?? 0, $liveJams))
            : 0;

        // ── KPI chuva acumulada última hora (CEMADEN) ───────────────────────
        $rainLastHour = $this->cemadenRepo->sumRainLastHourByPartner($partner);

        // ── Distribuições para gráficos ──────────────────────────────────────
        $alertsByType    = $this->alertRepo->countGroupByType($partner);
        $alertsBySubtype = $this->alertRepo->countGroupBySubtype($partner, 8);
        $jamsByLevel     = $this->jamRepo->countGroupByLevel($partner);
        $alertsPerHour   = $this->alertRepo->countPerHourLast24h($partner);
        $jamsPerHour     = $this->jamRepo->countPerHourLast24h($partner);

        return $this->render('dashboard/index.html.twig', [
            'partner' => $partner,
            'kpis'    => [
                'alerts'        => $alertCount,
                'jams'          => $jamCount,
                'cemaden'       => $cemadenCount,
                'cities'        => $cityCount,
                'links'         => $linkCount,
                'routes'        => $routeCount,
                'alerts24h'     => $alertsLast24h,
                'jams24h'       => $jamsLast24h,
                'avgSpeed'      => $avgJamSpeed,
                'avgDelay'      => $avgJamDelay,
                'totalLength'   => $totalJamLength,
                // últimas 3h
                'liveJams'      => count($liveJams),
                'maxJamLevel'   => $maxJamLevel,
                'liveAvgSpeed'  => $liveStats['avgSpeed'],
                'liveAvgDelay'  => $liveStats['avgDelay'],
                'liveTotalLen'  => $liveStats['totalLength'],
                // CEMADEN
                'rainLastHour'  => $rainLastHour,
            ],
            'alertsByType'    => $alertsByType,
            'alertsBySubtype' => $alertsBySubtype,
            'jamsByLevel'     => $jamsByLevel,
            'alertsPerHour'   => $alertsPerHour,
            'jamsPerHour'     => $jamsPerHour,
            // listas recentes
            'recentAlerts'    => $this->alertRepo->findRecentByPartner($partner, 10),
            'recentJams'      => $this->jamRepo->findRecentByPartner($partner, 5),
            'cemadenData'     => $this->cemadenRepo->findByPartner($partner),
            'routes'          => $this->tvtRouteRepo->findRecentByPartner($partner, 20),
            'cities'          => $this->cityRepo->findByPartner($partner),
            'links'           => $this->linkRepo->findByPartner($partner),
        ]);
    }

    #[Route('/mapa', name: 'map')]
    public function map(): Response
    {
        $partner = $this->tenantContext->requirePartner();

        return $this->render('dashboard/map.html.twig', [
            'partner' => $partner,
            'alerts'  => $this->alertRepo->findActiveByPartner($partner),
            'jams'    => $this->jamRepo->findActiveByPartner($partner),
            'cemaden' => $this->cemadenRepo->findByPartner($partner),
            'routes'  => $this->tvtRouteRepo->findRecentByPartner($partner, 50),
        ]);
    }
}
