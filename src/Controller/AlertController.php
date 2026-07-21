<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeAlertTypeRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alertas', name: 'alert_')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    public function __construct(
        private readonly TenantContext           $tenantContext,
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeAlertTypeRepository  $alertTypeRepo,
    ) {}

    /** Histórico de alertas com filtros e paginação */
    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner  = $this->tenantContext->requirePartner();
        $locale   = $request->getLocale() ?: 'pt';
        $type     = $request->query->get('type') ?: null;
        $subtype  = $request->query->get('subtype') ?: null;
        $city     = $request->query->get('city') ?: null;
        $dateFrom = $request->query->get('dateFrom') ?: null;
        $dateTo   = $request->query->get('dateTo') ?: null;
        $page     = max(1, (int) $request->query->get('page', 1));

        $result = $this->alertRepo->findFilteredByPartner(
            partner:  $partner,
            type:     $type,
            subtype:  $subtype,
            city:     $city,
            dateFrom: $dateFrom,
            dateTo:   $dateTo,
            page:     $page,
            limit:    30,
        );

        return $this->render('alert/index.html.twig', [
            'partner'      => $partner,
            'alerts'       => $result['items'],
            'total'        => $result['total'],
            'pages'        => $result['pages'],
            'page'         => $page,
            'type'         => $type,
            'subtype'      => $subtype,
            'city'         => $city,
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
            'types'        => $this->alertRepo->findDistinctTypes($partner),
            'subtypes'     => $this->alertRepo->findDistinctSubtypes($partner, $type),
            'cities'       => $this->alertRepo->findDistinctCities($partner),
            'typesMap'     => $this->alertTypeRepo->getTypesMap($locale),
            'subtypesMap'  => $this->alertTypeRepo->getSubtypesMap($locale),
        ]);
    }

    /** Alertas ao vivo agrupados por região — mapa interativo */
    #[Route('/ao-vivo', name: 'live')]
    public function live(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $locale  = $request->getLocale() ?: 'pt';
        $hours   = max(1, min(24, (int) $request->query->get('hours', 3)));
        $regions = $this->alertRepo->findLiveGroupedByRegion($partner, $hours);
        $alerts  = $this->alertRepo->findLiveByPartner($partner, $hours);

        return $this->render('alert/live.html.twig', [
            'partner'     => $partner,
            'regions'     => $regions,
            'alerts'      => $alerts,
            'hours'       => $hours,
            'total'       => array_sum(array_column($regions, 'count')),
            'typesMap'    => $this->alertTypeRepo->getTypesMap($locale),
            'subtypesMap' => $this->alertTypeRepo->getSubtypesMap($locale),
        ]);
    }

    /** API JSON para o mapa ao vivo (polling) */
    #[Route('/api/live', name: 'api_live')]
    public function apiLive(Request $request): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();
        $locale  = $request->getLocale() ?: 'pt';
        $hours   = max(1, min(24, (int) $request->query->get('hours', 3)));
        $alerts  = $this->alertRepo->findLiveByPartner($partner, $hours);

        $typesMap    = $this->alertTypeRepo->getTypesMap($locale);
        $subtypesMap = $this->alertTypeRepo->getSubtypesMap($locale);

        $data = array_map(fn($a) => [
            'id'           => $a->getId(),
            'lat'          => (float) $a->getLatitude(),
            'lng'          => (float) $a->getLongitude(),
            'type'         => $a->getType(),
            'typeLabel'    => $typesMap[$a->getType()] ?? $a->getType(),
            'subtype'      => $a->getSubtype(),
            'subtypeLabel' => $a->getSubtype() ? ($subtypesMap[$a->getType() . '|' . $a->getSubtype()] ?? $a->getSubtype()) : null,
            'street'       => $a->getStreet(),
            'city'         => $a->getCity(),
            'conf'         => $a->getConfidence(),
            'pub'          => $a->getPubMillis(),
        ], $alerts);

        return new JsonResponse($data);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(Request $request, int $id): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $locale  = $request->getLocale() ?: 'pt';
        $alert   = $this->alertRepo->findOneByPartner($id, $partner);

        if (!$alert) {
            throw $this->createNotFoundException('Alerta não encontrado.');
        }

        return $this->render('alert/show.html.twig', [
            'partner'     => $partner,
            'alert'       => $alert,
            'typesMap'    => $this->alertTypeRepo->getTypesMap($locale),
            'subtypesMap' => $this->alertTypeRepo->getSubtypesMap($locale),
        ]);
    }
}
