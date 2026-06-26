<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenDataRepository;
use App\Repository\MonitoredCityRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeRouteRepository;
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
        private readonly TenantContext               $tenantContext,
        private readonly WazeAlertRepository         $alertRepo,
        private readonly WazeTrafficJamRepository    $jamRepo,
        private readonly CemadenDataRepository       $cemadenRepo,
        private readonly WazeRouteRepository         $routeRepo,
        private readonly MonitoredCityRepository     $cityRepo,
        private readonly MonitoredLinkRepository     $linkRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $partner = $this->tenantContext->requirePartner();

        // ── KPIs básicos ──────────────────────────────────────────────────────
        $alertCount    = $this->alertRepo->countByPartner($partner);
        $jamCount      = $this->jamRepo->countByPartner($partner);
        $cemadenCount  = $this->cemadenRepo->countByPartner($partner);
        $cityCount     = $this->cityRepo->countByPartner($partner);
        $linkCount     = $this->linkRepo->countByPartner($partner);
        $routeCount    = $this->routeRepo->countByPartner($partner);

        // ── KPIs derivados ───────────────────────────────────────────────────
        $alertsLast24h     = $this->alertRepo->countLast24hByPartner($partner);
        $jamsLast24h       = $this->jamRepo->countLast24hByPartner($partner);
        $avgJamSpeed       = $this->jamRepo->avgSpeedKmhByPartner($partner);
        $avgJamDelay       = $this->jamRepo->avgDelaySecsByPartner($partner);
        $totalJamLength    = $this->jamRepo->totalLengthMByPartner($partner);

        // ── Distribuições para gráficos ─────────────────────────────────────────
        $alertsByType      = $this->alertRepo->countGroupByType($partner);
        $alertsBySubtype   = $this->alertRepo->countGroupBySubtype($partner, 8);
        $jamsByLevel       = $this->jamRepo->countGroupByLevel($partner);
        $alertsPerHour     = $this->alertRepo->countPerHourLast24h($partner);
        $jamsPerHour       = $this->jamRepo->countPerHourLast24h($partner);

        return $this->render('dashboard/index.html.twig', [
            'partner'        => $partner,
            'kpis' => [
                'alerts'       => $alertCount,
                'jams'         => $jamCount,
                'cemaden'      => $cemadenCount,
                'cities'       => $cityCount,
                'links'        => $linkCount,
                'routes'       => $routeCount,
                'alerts24h'    => $alertsLast24h,
                'jams24h'      => $jamsLast24h,
                'avgSpeed'     => $avgJamSpeed,
                'avgDelay'     => $avgJamDelay,
                'totalLength'  => $totalJamLength,
            ],
            'alertsByType'     => $alertsByType,
            'alertsBySubtype'  => $alertsBySubtype,
            'jamsByLevel'      => $jamsByLevel,
            'alertsPerHour'    => $alertsPerHour,
            'jamsPerHour'      => $jamsPerHour,
            // listas recentes
            'recentAlerts'     => $this->alertRepo->findRecentByPartner($partner, 10),
            'recentJams'       => $this->jamRepo->findRecentByPartner($partner, 5),
            'cemadenData'      => $this->cemadenRepo->findByPartner($partner),
            'routes'           => $this->routeRepo->findByPartner($partner),
            'cities'           => $this->cityRepo->findByPartner($partner),
            'links'            => $this->linkRepo->findByPartner($partner),
        ]);
    }

    #[Route('/mapa', name: 'map')]
    public function map(): Response
    {
        $partner = $this->tenantContext->requirePartner();

        return $this->render('dashboard/map.html.twig', [
            'partner'  => $partner,
            'alerts'   => $this->alertRepo->findActiveByPartner($partner),
            'jams'     => $this->jamRepo->findActiveByPartner($partner),
            'cemaden'  => $this->cemadenRepo->findByPartner($partner),
            'routes'   => $this->routeRepo->findByPartner($partner),
        ]);
    }
}
