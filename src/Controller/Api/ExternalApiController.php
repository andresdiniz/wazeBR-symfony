<?php

namespace App\Controller\Api;

use App\Entity\WazeTvtRouteHistory;
use App\Repository\WazeTvtRouteDefinitionRepository;
use App\Repository\WazeTvtRouteHistoryRepository;
use App\Repository\WazeTvtRouteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/external')]
class ExternalApiController extends AbstractController
{
    public function __construct(
        private readonly WazeTvtRouteRepository $routeRepo,
        private readonly WazeTvtRouteDefinitionRepository $definitionRepo,
        private readonly WazeTvtRouteHistoryRepository $historyRepo,
    ) {}

    /**
     * GET /api/external/tvt/routes?route_id=12699055487&limit=10
     * Retorna a definição atual da rota + histórico de métricas.
     */
    #[Route('/tvt/routes', name: 'api_external_tvt_routes', methods: ['GET'])]
    public function tvtRoutes(Request $request): JsonResponse
    {
        $externalRouteId = $request->query->get('route_id');
        $limit = max(1, (int) $request->query->get('limit', '10'));

        if (!$externalRouteId) {
            return new JsonResponse(['error' => 'Missing route_id parameter'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $route = $this->routeRepo->findOneByExternalRouteId($externalRouteId);
        if (!$route) {
            return new JsonResponse(['error' => 'Route not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $definition = $route->getCurrentDefinition();
        if (!$definition) {
            return new JsonResponse(['error' => 'Route definition not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $history = $this->historyRepo->findRecentByRoute($route->getId(), $limit);

        $data = [
            'definition' => [
                'routeId'         => $route->getExternalRouteId(),
                'name'            => $definition->getName(),
                'originName'      => $definition->getOriginName(),
                'destinationName' => $definition->getDestinationName(),
                'distanceMeters'  => $definition->getDistanceMeters(),
                'geometry'        => $definition->getGeometry(),
                'versionNumber'   => $definition->getVersionNumber(),
                'validFrom'       => $definition->getValidFrom()->format('c'),
            ],
            'executions' => array_map(
                fn(WazeTvtRouteHistory $h) => $this->serializeHistory($h),
                $history
            ),
        ];

        return new JsonResponse($data);
    }

    /**
     * GET /api/external/tvt/routes/latest?route_id[]=123&route_id[]=456
     * Retorna a última métrica de cada rota solicitada.
     */
    #[Route('/tvt/routes/latest', name: 'api_external_tvt_routes_latest', methods: ['GET'])]
    public function tvtRoutesLatest(Request $request): JsonResponse
    {
        $externalRouteIds = $request->query->all('route_id');

        if (empty($externalRouteIds)) {
            return new JsonResponse(['error' => 'Missing route_id parameter(s)'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $result = [];
        foreach ($externalRouteIds as $externalRouteId) {
            $route = $this->routeRepo->findOneByExternalRouteId((string)$externalRouteId);
            if (!$route) {
                continue;
            }

            $history = $this->historyRepo->findRecentByRoute($route->getId(), 1);
            if (!empty($history)) {
                $result[$externalRouteId] = $this->serializeHistory($history[0]);
                $result[$externalRouteId]['routeId'] = $externalRouteId;
            }
        }

        return new JsonResponse($result);
    }

    private function serializeHistory(WazeTvtRouteHistory $h): array
    {
        return [
            'id'                => $h->getId(),
            'observedAt'        => $h->getObservedAt()->format('c'),
            'travelTimeSeconds' => $h->getTravelTimeSeconds(),
            'travelTimeMinutes' => $h->getTravelTimeMinutes() !== null ? (float)$h->getTravelTimeMinutes() : null,
            'speedKmh'          => $h->getSpeedKmh() !== null ? (float)$h->getSpeedKmh() : null,
            'delaySeconds'      => $h->getDelaySeconds(),
            'lengthMeters'      => $h->getLengthMeters(),
            'status'            => $h->getStatus(),
        ];
    }
}
