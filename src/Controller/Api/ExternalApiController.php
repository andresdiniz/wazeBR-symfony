<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\CemadenDataRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API externa pública para parceiros/clientes (autenticação via X-Api-Token).
 * O tenant é resolvido pelo TenantTokenListener antes de chegar aqui.
 */
#[Route('/api/externa', name: 'api_externa_')]
class ExternalApiController extends AbstractController
{
    public function __construct(
        private readonly TenantContext            $tenantContext,
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly CemadenDataRepository    $cemadenRepo,
    ) {}

    #[Route('/alertas', name: 'alerts', methods: ['GET'])]
    public function alerts(Request $request): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();
        $type    = $request->query->get('type');
        $city    = $request->query->get('city');
        $limit   = min(500, max(1, (int) $request->query->get('limit', 100)));

        $alerts = $this->alertRepo->findFilteredByPartner(
            partner: $partner,
            type: $type ?: null,
            city: $city ?: null,
            page: 1,
            limit: $limit,
        );

        return $this->json([
            'partner' => $partner->getSlug(),
            'total'   => count($alerts),
            'data'    => array_map(fn ($a) => [
                'id'          => $a->getWazeId(),
                'type'        => $a->getType(),
                'subtype'     => $a->getSubtype(),
                'street'      => $a->getStreet(),
                'city'        => $a->getCity(),
                'lat'         => $a->getLatitude(),
                'lng'         => $a->getLongitude(),
                'reliability' => $a->getReliability(),
                'confidence'  => $a->getConfidence(),
                'pubMillis'   => $a->getPubMillis(),
            ], $alerts),
        ]);
    }

    #[Route('/congestionamentos', name: 'jams', methods: ['GET'])]
    public function jams(Request $request): JsonResponse
    {
        $partner  = $this->tenantContext->requirePartner();
        $minLevel = (int) $request->query->get('level', 0);
        $limit    = min(500, max(1, (int) $request->query->get('limit', 100)));

        $jams = $this->jamRepo->findFilteredByPartner(
            partner: $partner,
            minLevel: $minLevel > 0 ? $minLevel : null,
            city: null,
            page: 1,
            limit: $limit,
        );

        return $this->json([
            'partner' => $partner->getSlug(),
            'total'   => count($jams),
            'data'    => array_map(fn ($j) => [
                'id'     => $j->getWazeId(),
                'street' => $j->getStreet(),
                'city'   => $j->getCity(),
                'level'  => $j->getLevel(),
                'speed'  => $j->getSpeedKmh(),
                'length' => $j->getLength(),
                'delay'  => $j->getDelay(),
            ], $jams),
        ]);
    }

    #[Route('/cemaden', name: 'cemaden', methods: ['GET'])]
    public function cemaden(Request $request): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();
        $level   = $request->query->get('level');
        $state   = $request->query->get('state');

        $data = $this->cemadenRepo->findFilteredByPartner(
            partner: $partner,
            alertLevel: $level ?: null,
            state: $state ?: null,
        );

        return $this->json([
            'partner' => $partner->getSlug(),
            'total'   => count($data),
            'data'    => array_map(fn ($c) => [
                'station'    => $c->getStationCode(),
                'name'       => $c->getStationName(),
                'city'       => $c->getMunicipality(),
                'state'      => $c->getState(),
                'lat'        => $c->getLatitude(),
                'lng'        => $c->getLongitude(),
                'rain'       => $c->getAccumulatedRain(),
                'level'      => $c->getAlertLevel(),
                'measuredAt' => $c->getMeasuredAt()?->format('c'),
            ], $data),
        ]);
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();

        return $this->json([
            'partner'   => $partner->getSlug(),
            'name'      => $partner->getName(),
            'isActive'  => $partner->isActive(),
            'bbox'      => $partner->getBbox(),
            'states'    => $partner->getCemadenStates(),
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }
}
