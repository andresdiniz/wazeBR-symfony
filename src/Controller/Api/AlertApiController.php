<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\WazeAlertRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API interna JSON para alertas (usada pelo frontend SPA/JS do parceiro).
 * Autenticação via sessão (usuário logado) — tenant resolvido pelo TenantContext.
 */
#[Route('/api/alertas', name: 'api_alert_')]
class AlertApiController extends AbstractController
{
    public function __construct(
        private readonly TenantContext       $tenantContext,
        private readonly WazeAlertRepository $alertRepo,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();
        $type    = $request->query->get('type');
        $city    = $request->query->get('city');
        $limit   = min(200, max(1, (int) $request->query->get('limit', 50)));

        $alerts = $this->alertRepo->findFilteredByPartner(
            partner: $partner,
            type: $type ?: null,
            city: $city ?: null,
            page: 1,
            limit: $limit,
        );

        return $this->json(array_map(fn ($a) => [
            'id'          => $a->getId(),
            'wazeId'      => $a->getWazeId(),
            'type'        => $a->getType(),
            'subtype'     => $a->getSubtype(),
            'street'      => $a->getStreet(),
            'city'        => $a->getCity(),
            'lat'         => $a->getLatitude(),
            'lng'         => $a->getLongitude(),
            'reliability' => $a->getReliability(),
            'confidence'  => $a->getConfidence(),
            'pubMillis'   => $a->getPubMillis(),
        ], $alerts));
    }

    #[Route('/mapa', name: 'map', methods: ['GET'])]
    public function mapData(): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();
        $alerts  = $this->alertRepo->findActiveByPartner($partner);

        return $this->json(array_map(fn ($a) => [
            'id'     => $a->getId(),
            'type'   => $a->getType(),
            'lat'    => $a->getLatitude(),
            'lng'    => $a->getLongitude(),
            'street' => $a->getStreet(),
        ], $alerts));
    }
}
