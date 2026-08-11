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
    /** Períodos fixos do filtro (além de datas manuais). */
    private const PERIOD_PRESETS = [
        'today'      => ['label' => 'Hoje',   'days' => 0],
        'yesterday'  => ['label' => 'Ontem',  'days' => 1],
        'week'       => ['label' => '7 dias', 'days' => 7],
        'month'      => ['label' => '30 dias','days' => 30],
        'six_months' => ['label' => '6 meses','days' => 182],
        'year'       => ['label' => '1 ano',  'days' => 365],
    ];

    public function __construct(
        private readonly TenantContext           $tenantContext,
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeAlertTypeRepository  $alertTypeRepo,
    ) {}

    /** Histórico de alertas: filtros, paginação e análises (gráficos, ranking de vias, hotspots) */
    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner  = $this->tenantContext->requirePartner();
        $locale   = $request->getLocale() ?: 'pt';

        $type          = $request->query->get('type') ?: null;
        $subtype       = $request->query->get('subtype') ?: null;
        $city          = $request->query->get('city') ?: null;
        $street        = $request->query->get('street') ?: null;
        $excludeStreet = $request->query->get('excludeStreet') ?: null;
        $period        = $request->query->get('period') ?: null;
        $dateFrom      = $request->query->get('dateFrom') ?: null;
        $dateTo        = $request->query->get('dateTo') ?: null;
        $page          = max(1, (int) $request->query->get('page', 1));

        // Período fixo preenche dateFrom/dateTo (dia civil de Brasília) se não houver datas manuais
        if ($period && isset(self::PERIOD_PRESETS[$period]) && !$dateFrom && !$dateTo) {
            $tz = new \DateTimeZone('America/Sao_Paulo');
            $today = new \DateTimeImmutable('now', $tz);

            if ($period === 'yesterday') {
                $dateFrom = $dateTo = $today->modify('-1 day')->format('Y-m-d');
            } else {
                $dateFrom = $today->modify('-' . self::PERIOD_PRESETS[$period]['days'] . ' days')->format('Y-m-d');
                $dateTo = $today->format('Y-m-d');
            }
        }

        $filters = compact('type', 'subtype', 'city', 'street', 'excludeStreet', 'dateFrom', 'dateTo');

        $result = $this->alertRepo->findFilteredByPartner($partner, $filters, $page, 30);

        $typesMap = $this->alertTypeRepo->getTypesMap($locale);
        $subtypesMap = $this->alertTypeRepo->getSubtypesMap($locale);

        $bySubtypeRaw = $this->alertRepo->countBySubtypeFiltered($partner, $filters, 10);
        $bySubtype = array_map(function (array $r) use ($typesMap, $subtypesMap) {
            $key = $r['type'] . '|' . $r['subtype'];
            $label = $r['subtype'] ? ($subtypesMap[$key] ?? $r['subtype']) : ($typesMap[$r['type']] ?? $r['type']);
            return ['label' => $label, 'total' => $r['total']];
        }, $bySubtypeRaw);

        return $this->render('alert/index.html.twig', [
            'partner'       => $partner,
            'alerts'        => $result['items'],
            'total'         => $result['total'],
            'pages'         => $result['pages'],
            'page'          => $page,
            'type'          => $type,
            'subtype'       => $subtype,
            'city'          => $city,
            'street'        => $street,
            'excludeStreet' => $excludeStreet,
            'period'        => $period,
            'periods'       => self::PERIOD_PRESETS,
            'dateFrom'      => $dateFrom,
            'dateTo'        => $dateTo,
            'types'         => $this->alertRepo->findDistinctTypes($partner),
            'subtypes'      => $this->alertRepo->findDistinctSubtypes($partner, $type),
            'cities'        => $this->alertRepo->findDistinctCities($partner),
            'streets'       => $this->alertRepo->findDistinctStreets($partner),
            'typesMap'      => $typesMap,
            'subtypesMap'   => $subtypesMap,

            // análises do conjunto filtrado inteiro (não só a página atual)
            'bySubtype'     => $bySubtype,
            'byConfidence'  => $this->alertRepo->countByConfidenceFiltered($partner, $filters),
            'byDay'         => $this->alertRepo->countByDayFiltered($partner, $filters),
            'byHour'        => $this->alertRepo->countByHourOfDayFiltered($partner, $filters),
            'byWeekday'     => $this->alertRepo->countByWeekdayFiltered($partner, $filters),
            'topStreets'    => $this->alertRepo->topStreetsFiltered($partner, $filters, 10),
            'hotspots'      => $this->alertRepo->findHotspotsFiltered($partner, $filters, 15),
            'mapAlerts'     => $this->alertRepo->findForMapFiltered($partner, $filters, 500),
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
