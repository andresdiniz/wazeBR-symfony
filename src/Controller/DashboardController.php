<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/', name: 'app_')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    #[Route('', name: 'dashboard')]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'summary' => $this->dashboardService->getSummary(),
        ]);
    }

    #[Route('mapa/alertas', name: 'map_alerts')]
    public function mapAlerts(Request $request): Response
    {
        $hours  = $request->query->getInt('h', 2);
        $city   = $request->query->getString('city', '');

        return $this->render('dashboard/map_alerts.html.twig', [
            'alerts' => $this->dashboardService->getAlertsForMap($hours),
            'hours'  => $hours,
            'city'   => $city,
        ]);
    }

    #[Route('mapa/transito', name: 'map_traffic')]
    public function mapTraffic(Request $request): Response
    {
        $hours = $request->query->getInt('h', 2);

        return $this->render('dashboard/map_traffic.html.twig', [
            'jams'  => $this->dashboardService->getTrafficForMap($hours),
            'hours' => $hours,
        ]);
    }

    #[Route('cemaden', name: 'cemaden')]
    public function cemaden(): Response
    {
        return $this->render('dashboard/cemaden.html.twig', [
            'stations' => $this->dashboardService->getCemadenForMap(),
        ]);
    }
}
