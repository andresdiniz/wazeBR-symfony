<?php

namespace App\Controller\Api;

use App\Repository\WazeTvtRouteDefinitionRepository;
use App\Repository\WazeTvtRouteExecutionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/external')]
class ExternalApiController extends AbstractController
{
    public function __construct(
        private WazeTvtRouteDefinitionRepository $definitionRepo,
        private WazeTvtRouteExecutionRepository $executionRepo,
    ) {
    }

    #[Route('/tvt/routes', name: 'api_external_tvt_routes', methods: ['GET'])]
    public function tvtRoutes(Request $request): JsonResponse
    {
        $routeId = $request->query->get('route_id');
        $limit = (int) $request->query->get('limit', '10');

        if (!$routeId) {
            return new JsonResponse(['error' => 'Missing route_id parameter'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $definition = $this->definitionRepo->findOneByRouteId($routeId);
        if (!$definition) {
            return new JsonResponse(['error' => 'Route definition not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $executions = $this->executionRepo->findByRouteId($routeId, $limit);

        $data = [
            'definition' => [
                'routeId' => $definition->getRouteId(),
                'name' => $definition->getName(),
                'bbox' => $definition->getBbox() ? json_decode($definition->getBbox(), true) : null,
                'line' => $definition->getLine() ? json_decode($definition->getLine(), true) : null,
            ],
            'executions' => array_map(function (WazeTvtRouteExecution $exec) {
                return [
                    'id' => $exec->getId(),
                    'timestamp' => $exec->getTimestamp()?->format('c'),
                    'duration' => $exec->getDuration(),
                    'length' => $exec->getLength(),
                    'irregularities' => $exec->getIrregularities(),
                    'trafficJams' => $exec->getTrafficJams(),
                    'avgSpeed' => $exec->getAvgSpeed(),
                ];
            }, $executions),
        ];

        return new JsonResponse($data);
    }

    #[Route('/tvt/routes/latest', name: 'api_external_tvt_routes_latest', methods: ['GET'])]
    public function tvtRoutesLatest(Request $request): JsonResponse
    {
        $routeIds = $request->query->all('route_id');

        if (empty($routeIds)) {
            return new JsonResponse(['error' => 'Missing route_id parameter(s)'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $result = [];
        foreach ($routeIds as $routeId) {
            $executions = $this->executionRepo->findByRouteId($routeId, 1);
            if (!empty($executions)) {
                $exec = $executions[0];
                $result[$routeId] = [
                    'routeId' => $routeId,
                    'timestamp' => $exec->getTimestamp()?->format('c'),
                    'duration' => $exec->getDuration(),
                    'length' => $exec->getLength(),
                    'irregularities' => $exec->getIrregularities(),
                    'trafficJams' => $exec->getTrafficJams(),
                    'avgSpeed' => $exec->getAvgSpeed(),
                ];
            }
        }

        return new JsonResponse($result);
    }
}
