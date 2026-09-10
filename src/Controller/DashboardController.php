<?php

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\WazeTvtRouteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard')]
    public function index(
        WazeAlertRepository $alertRepository,
        WazeTrafficJamRepository $jamRepository,
        WazeTvtRouteRepository $tvtRouteRepository,
    ): Response {
        $partner = $this->getUser()->getPartner();
        $partnerId = $partner->getId();

        $now = new \DateTime();
        $startOfDay = (clone $now)->setTime(0, 0);
        $endOfDay = (clone $now)->setTime(23, 59, 59);

        $alertsCount = $alertRepository->countInPeriod($startOfDay, $endOfDay, $partnerId);
        $jamsCount = $jamRepository->countInPeriod($startOfDay, $endOfDay, $partnerId);
        $routesCount = $tvtRouteRepository->count(['partner' => $partnerId]);

        $alerts = $alertRepository->findActiveByPartner($partnerId);
        $jams = $jamRepository->findActiveByPartner($partnerId);
        $routes = $tvtRouteRepository->findActiveByPartner($partnerId);

        return $this->render('dashboard/index.html.twig', [
            'alerts_count' => $alertsCount,
            'jams_count' => $jamsCount,
            'routes_count' => $routesCount,
            'alerts' => $alerts,
            'jams' => $jams,
            'routes' => $routes,
        ]);
    }
}
