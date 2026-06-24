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
        private readonly TenantContext            $tenantContext,
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly CemadenDataRepository    $cemadenRepo,
        private readonly WazeRouteRepository      $routeRepo,
        private readonly MonitoredCityRepository  $cityRepo,
        private readonly MonitoredLinkRepository  $linkRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $partner = $this->tenantContext->requirePartner();

        return $this->render('dashboard/index.html.twig', [
            'partner'      => $partner,
            'alertCount'   => $this->alertRepo->countByPartner($partner),
            'jamCount'     => $this->jamRepo->countByPartner($partner),
            'cemadenCount' => $this->cemadenRepo->countByPartner($partner),
            'recentAlerts' => $this->alertRepo->findRecentByPartner($partner, 10),
            'recentJams'   => $this->jamRepo->findRecentByPartner($partner, 5),
            'cemadenData'  => $this->cemadenRepo->findByPartner($partner),
            'routes'       => $this->routeRepo->findByPartner($partner),
            'cities'       => $this->cityRepo->findByPartner($partner),
            'links'        => $this->linkRepo->findByPartner($partner),
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
            'routes'  => $this->routeRepo->findByPartner($partner),
        ]);
    }
}
