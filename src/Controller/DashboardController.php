<?php

namespace App\Controller;

use App\Repository\MonitoredCityRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeIrregularityRepository;
use App\Repository\WazeRouteRepository;
use App\Repository\WazeTrafficJamRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly WazeAlertRepository $alertRepository,
        private readonly WazeTrafficJamRepository $trafficJamRepository,
        private readonly WazeRouteRepository $routeRepository,
        private readonly WazeIrregularityRepository $irregularityRepository,
        private readonly MonitoredCityRepository $cityRepository,
    ) {
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $alerts = $this->loadCollection(
            fn (): array => $this->alertRepository->findBy([], ['lastSeenAt' => 'DESC'], 100)
        );
        $jams = $this->loadCollection(
            fn (): array => $this->trafficJamRepository->findBy([], ['lastSeenAt' => 'DESC'], 100)
        );
        $routes = $this->loadCollection(
            fn (): array => $this->routeRepository->findBy([], ['id' => 'DESC'], 100)
        );
        $irregularities = $this->loadCollection(
            fn (): array => $this->irregularityRepository->findBy([], ['lastSeenAt' => 'DESC'], 100)
        );
        $cities = $this->loadCollection(
            fn (): array => $this->cityRepository->findBy([], ['name' => 'ASC'], 100)
        );

        return $this->render('dashboard/index.html.twig', [
            'alerts' => $alerts,
            'alerts_count' => count($alerts),
            'jams' => $jams,
            'jams_count' => count($jams),
            'routes' => $routes,
            'routes_count' => count($routes),
            'irregularities' => $irregularities,
            'irregularities_count' => count($irregularities),
            'cities' => $cities,
            'notifications' => [],
            'counts' => [],
        ]);
    }

    /**
     * Keeps the dashboard renderable when a source has no records or a
     * partially configured database does not contain an optional table yet.
     */
    private function loadCollection(callable $loader): array
    {
        try {
            return $loader();
        } catch (\Throwable) {
            return [];
        }
    }
}
