<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\CemadenDataRepository;
use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/api', name: 'api_')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeTrafficJamRepository  $jamRepo,
        private readonly CemadenDataRepository     $cemadenRepo,
        private readonly DashboardService          $dashboardService,
    ) {}

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        return $this->json($this->dashboardService->getSummary());
    }

    #[Route('/alertas', name: 'alerts', methods: ['GET'])]
    public function alerts(Request $request): JsonResponse
    {
        $hours = $request->query->getInt('h', 2);
        $city  = $request->query->getString('city', '') ?: null;
        $type  = $request->query->getString('type', '') ?: null;

        $data = array_map(
            fn($a) => [
                'id'          => $a->getWazeId(),
                'type'        => $a->getType(),
                'subtype'     => $a->getSubtype(),
                'street'      => $a->getStreet(),
                'city'        => $a->getCity(),
                'lat'         => $a->getLatitude(),
                'lng'         => $a->getLongitude(),
                'reliability' => $a->getReliability(),
                'pub_millis'  => $a->getPubMillis(),
            ],
            $this->alertRepo->findFiltered($hours, $city, $type)
        );

        return $this->json(['count' => count($data), 'data' => $data]);
    }

    #[Route('/transito', name: 'traffic', methods: ['GET'])]
    public function traffic(Request $request): JsonResponse
    {
        $hours = $request->query->getInt('h', 2);
        $city  = $request->query->getString('city', '') ?: null;
        $level = $request->query->has('level') ? $request->query->getInt('level') : null;

        $data = array_map(
            fn($j) => [
                'id'        => $j->getWazeId(),
                'street'    => $j->getStreet(),
                'city'      => $j->getCity(),
                'level'     => $j->getLevel(),
                'speed_kmh' => $j->getSpeedKmh(),
                'length'    => $j->getLength(),
                'delay'     => $j->getDelay(),
                'pub_millis'=> $j->getPubMillis(),
            ],
            $this->jamRepo->findFiltered($hours, $city, $level)
        );

        return $this->json(['count' => count($data), 'data' => $data]);
    }

    #[Route('/cemaden', name: 'cemaden', methods: ['GET'])]
    public function cemaden(): JsonResponse
    {
        $data = array_map(
            fn($c) => [
                'code'       => $c->getStationCode(),
                'name'       => $c->getStationName(),
                'city'       => $c->getMunicipality(),
                'state'      => $c->getState(),
                'lat'        => $c->getLatitude(),
                'lng'        => $c->getLongitude(),
                'rain'       => $c->getAccumulatedRain(),
                'alert'      => $c->getAlertLevel(),
                'measured'   => $c->getMeasuredAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->cemadenRepo->findActiveAlerts()
        );

        return $this->json(['count' => count($data), 'data' => $data]);
    }
}
