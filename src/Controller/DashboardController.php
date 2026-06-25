<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenDataRepository;
use App\Repository\MonitoredCityRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTvtSnapshotRepository;
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
        private readonly WazeTvtSnapshotRepository   $snapshotRepo,
        private readonly MonitoredCityRepository     $cityRepo,
        private readonly MonitoredLinkRepository     $linkRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $partner = $this->tenantContext->requirePartner();

        $alertCount    = $this->alertRepo->countByPartner($partner);
        $jamCount      = $this->jamRepo->countByPartner($partner);
        $cemadenCount  = $this->cemadenRepo->countByPartner($partner);
        $cityCount     = $this->cityRepo->countByPartner($partner);
        $linkCount     = $this->linkRepo->countByPartner($partner);
        $snapshotCount = $this->snapshotRepo->countByPartner($partner);

        return $this->render('dashboard/index.html.twig', [
            'partner'      => $partner,
            'kpis' => [
                'alerts'    => $alertCount,
                'jams'      => $jamCount,
                'cemaden'   => $cemadenCount,
                'cities'    => $cityCount,
                'links'     => $linkCount,
                'routes'    => $snapshotCount,
            ],
            // variáveis individuais mantidas para retrocompatibilidade com o template
            'alertCount'   => $alertCount,
            'jamCount'     => $jamCount,
            'cemadenCount' => $cemadenCount,
            'recentAlerts' => $this->alertRepo->findRecentByPartner($partner, 10),
            'recentJams'   => $this->jamRepo->findRecentByPartner($partner, 5),
            'cemadenData'  => $this->cemadenRepo->findByPartner($partner),
            'routes'       => $this->snapshotRepo->findByPartner($partner),
            'cities'       => $this->cityRepo->findByPartner($partner),
            'links'        => $this->linkRepo->findByPartner($partner),
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
            'routes'   => $this->snapshotRepo->findByPartner($partner),
        ]);
    }
}
