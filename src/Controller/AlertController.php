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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alertas', name: 'alert_')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    private const PERIOD_PRESETS = [
        'today' => ['label' => 'Hoje', 'days' => 0],
        'yesterday' => ['label' => 'Ontem', 'days' => 1],
        'week' => ['label' => '7 dias', 'days' => 7],
        'month' => ['label' => '30 dias', 'days' => 30],
        'six_months' => ['label' => '6 meses', 'days' => 182],
        'year' => ['label' => '1 ano', 'days' => 365],
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WazeAlertRepository $alertRepo,
        private readonly WazeAlertTypeRepository $alertTypeRepo,
    ) {}

    private function filtersFromRequest(Request $request): array
    {
        $type = $request->query->get('type') ?: null;
        $subtype = $request->query->get('subtype') ?: null;
        $city = $request->query->get('city') ?: null;
        $street = $request->query->get('street') ?: null;
        $excludeStreet = $request->query->get('excludeStreet') ?: null;
        $dateFrom = $request->query->get('dateFrom') ?: null;
        $dateTo = $request->query->get('dateTo') ?: null;
        $period = $request->query->get('period') ?: null;
        if ($period && isset(self::PERIOD_PRESETS[$period]) && !$dateFrom && !$dateTo) {
            $tz = new \DateTimeZone('America/Sao_Paulo');
            $today = new \DateTimeImmutable('now', $tz);
            if ($period === 'yesterday') $dateFrom = $dateTo = $today->modify('-1 day')->format('Y-m-d');
            else { $dateFrom = $today->modify('-' . self::PERIOD_PRESETS[$period]['days'] . ' days')->format('Y-m-d'); $dateTo = $today->format('Y-m-d'); }
        }
        return compact('type', 'subtype', 'city', 'street', 'excludeStreet', 'dateFrom', 'dateTo');
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response { $partner = $this->tenantContext->requirePartner(); $locale = $request->getLocale() ?: 'pt'; $type = $request->query->get('type') ?: null; $subtype = $request->query->get('subtype') ?: null; $city = $request->query->get('city') ?: null; $street = $request->query->get('street') ?: null; $excludeStreet = $request->query->get('excludeStreet') ?: null; $period = $request->query->get('period') ?: null; $dateFrom = $request->query->get('dateFrom') ?: null; $dateTo = $request->query->get('dateTo') ?: null; $page = max(1, (int) $request->query->get('page', 1)); $filters = $this->filtersFromRequest($request); $result = $this->alertRepo->findFilteredByPartner($partner, $filters, $page, 30); $typesMap = $this->alertTypeRepo->getTypesMap($locale); $subtypesMap = $this->alertTypeRepo->getSubtypesMap($locale); $bySubtypeRaw = $this->alertRepo->countBySubtypeFiltered($partner, $filters, 10); $bySubtype = array_map(function (array $r) use ($typesMap, $subtypesMap) { $key = $r['type'] . '|' . $r['subtype']; $label = $r['subtype'] ? ($subtypesMap[$key] ?? $r['subtype']) : ($typesMap[$r['type']] ?? $r['type']); return ['label' => $label, 'total' => $r['total']]; }, $bySubtypeRaw); return $this->render('alert/index.html.twig', ['partner' => $partner, 'alerts' => $result['items'], 'total' => $result['total'], 'pages' => $result['pages'], 'page' => $page, 'type' => $type, 'subtype' => $subtype, 'city' => $city, 'street' => $street, 'excludeStreet' => $excludeStreet, 'period' => $period, 'periods' => self::PERIOD_PRESETS, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'types' => $this->alertRepo->findDistinctTypes($partner), 'subtypes' => $this->alertRepo->findDistinctSubtypes($partner, $type), 'cities' => $this->alertRepo->findDistinctCities($partner), 'streets' => $this->alertRepo->findDistinctStreets($partner), 'typesMap' => $typesMap, 'subtypesMap' => $subtypesMap, 'bySubtype' => $bySubtype, 'byConfidence' => $this->alertRepo->countByConfidenceFiltered($partner, $filters), 'byDay' => $this->alertRepo->countByDayFiltered($partner, $filters), 'byHour' => $this->alertRepo->countByHourOfDayFiltered($partner, $filters), 'byWeekday' => $this->alertRepo->countByWeekdayFiltered($partner, $filters), 'topStreets' => $this->alertRepo->topStreetsFiltered($partner, $filters, 10), 'hotspots' => $this->alertRepo->findHotspotsFiltered($partner, $filters, 15), 'mapAlerts' => $this->alertRepo->findForMapFiltered($partner, $filters, 500)]); }

    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse { $partner = $this->tenantContext->requirePartner(); $locale = $request->getLocale() ?: 'pt'; $filters = $this->filtersFromRequest($request); $typesMap = $this->alertTypeRepo->getTypesMap($locale); $subtypesMap = $this->alertTypeRepo->getSubtypesMap($locale); $alerts = $this->alertRepo->findAllFilteredByPartnerForExport($partner, $filters); $response = new StreamedResponse(function () use ($alerts, $typesMap, $subtypesMap): void { $output = fopen('php://output', 'wb'); fwrite($output, "\xEF\xBB\xBF"); fputcsv($output, ['ID', 'Tipo', 'Subtipo', 'Via', 'Cidade', 'Publicado', 'Confiança', 'Curtidas', 'Latitude', 'Longitude'], ';'); $timezone = new \DateTimeZone('America/Sao_Paulo'); foreach ($alerts as $alert) { $pubMillis = (int) ($alert->getPubMillis() ?? 0); $publishedAt = $pubMillis > 0 ? (new \DateTimeImmutable('@' . intdiv($pubMillis, 1000)))->setTimezone($timezone)->format('d/m/Y H:i:s') : ''; fputcsv($output, [$alert->getId(), $typesMap[$alert->getType()] ?? $alert->getType(), $alert->getSubtype() ? ($subtypesMap[$alert->getType() . '|' . $alert->getSubtype()] ?? $alert->getSubtype()) : '', $alert->getStreet() ?? '', $alert->getCity() ?? '', $publishedAt, $alert->getConfidence() ?? '', $alert->getNThumbsUp() ?? 0, $alert->getLatitude() ?? '', $alert->getLongitude() ?? ''], ';'); } fclose($output); }); $response->headers->set('Content-Type', 'text/csv; charset=UTF-8'); $response->headers->set('Content-Disposition', 'attachment; filename="alertas-filtrados-' . date('Y-m-d_H-i-s') . '.csv"'); return $response; }

    #[Route('/ao-vivo', name: 'live', methods: ['GET'])]
    public function live(Request $request): Response { $partner = $this->tenantContext->requirePartner(); $locale = $request->getLocale() ?: 'pt'; $alerts = $this->alertRepo->findActiveByPartner($partner, 10); $regions = []; foreach ($alerts as $alert) { $city = $alert->getCity() ?: 'Sem cidade'; $regions[$city] = ($regions[$city] ?? 0) + 1; } arsort($regions); $regionRows = array_map(static fn($city, $count) => ['city' => $city, 'count' => $count], array_keys($regions), array_values($regions)); dump(['rota' => 'alert_live', 'total_alertas_ativos' => count($alerts), 'regioes' => $regionRows, 'ids' => array_map(static fn($alert) => $alert->getId(), $alerts)]); return $this->render('alert/live.html.twig', ['partner' => $partner, 'regions' => $regionRows, 'alerts' => $alerts, 'hours' => 0, 'total' => count($alerts), 'typesMap' => $this->alertTypeRepo->getTypesMap($locale), 'subtypesMap' => $this->alertTypeRepo->getSubtypesMap($locale)]); }

    #[Route('/api/live', name: 'api_live', methods: ['GET'])]
    public function apiLive(Request $request): JsonResponse { $partner = $this->tenantContext->requirePartner(); $locale = $request->getLocale() ?: 'pt'; $alerts = $this->alertRepo->findActiveByPartner($partner, 10); $typesMap = $this->alertTypeRepo->getTypesMap($locale); $subtypesMap = $this->alertTypeRepo->getSubtypesMap($locale); $data = array_map(static function ($alert) use ($typesMap, $subtypesMap): array { $type = $alert->getType(); $subtype = $alert->getSubtype(); return ['id' => $alert->getId(), 'lat' => (float) $alert->getLatitude(), 'lng' => (float) $alert->getLongitude(), 'type' => $type, 'typeLabel' => $typesMap[$type] ?? $type, 'subtype' => $subtype, 'subtypeLabel' => $subtype ? ($subtypesMap[$type . '|' . $subtype] ?? $subtype) : null, 'street' => $alert->getStreet(), 'city' => $alert->getCity(), 'conf' => $alert->getConfidence(), 'pub' => $alert->getPubMillis()]; }, $alerts); return new JsonResponse($data); }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response { $partner = $this->tenantContext->requirePartner(); $locale = $request->getLocale() ?: 'pt'; $alert = $this->alertRepo->findOneByPartner($id, $partner); if (!$alert) throw $this->createNotFoundException('Alerta não encontrado.'); return $this->render('alert/show.html.twig', ['partner' => $partner, 'alert' => $alert, 'typesMap' => $this->alertTypeRepo->getTypesMap($locale), 'subtypesMap' => $this->alertTypeRepo->getSubtypesMap($locale)]); }
}
