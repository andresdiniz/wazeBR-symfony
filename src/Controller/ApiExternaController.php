<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Service\WazeApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API pública/externa com Basic Auth via token de header.
 * Substitui api_externa.php.
 */
#[Route('/api/externa', name: 'api_externa_')]
class ApiExternaController extends AbstractController
{
    public function __construct(
        private readonly WazeAlertRepository     $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly WazeApiService           $wazeApiService,
        private readonly string                   $apiExternaToken,
    ) {}

    #[Route('/alertas', name: 'alerts', methods: ['GET'])]
    public function alerts(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $hours = $request->query->getInt('h', 2);
        $data  = array_map(
            fn($a) => [
                'uuid'        => $a->getWazeId(),
                'type'        => $a->getType(),
                'subtype'     => $a->getSubtype(),
                'street'      => $a->getStreet(),
                'city'        => $a->getCity(),
                'location'    => ['lat' => $a->getLatitude(), 'lng' => $a->getLongitude()],
                'reliability' => $a->getReliability(),
                'pub_millis'  => $a->getPubMillis(),
            ],
            $this->alertRepo->findRecentAlerts($hours)
        );

        return $this->json(['generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'count' => count($data), 'alerts' => $data]);
    }

    #[Route('/transito', name: 'traffic', methods: ['GET'])]
    public function traffic(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $hours = $request->query->getInt('h', 2);
        $data  = array_map(
            fn($j) => [
                'uuid'      => $j->getWazeId(),
                'street'    => $j->getStreet(),
                'city'      => $j->getCity(),
                'level'     => $j->getLevel(),
                'speed_kmh' => $j->getSpeedKmh(),
                'length_m'  => $j->getLength(),
                'delay_s'   => $j->getDelay(),
            ],
            $this->jamRepo->findRecentJams($hours)
        );

        return $this->json(['generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'count' => count($data), 'jams' => $data]);
    }

    private function isAuthorized(Request $request): bool
    {
        $token = $request->headers->get('X-Api-Token') ?? $request->query->getString('token', '');
        return hash_equals($this->apiExternaToken, $token);
    }
}
