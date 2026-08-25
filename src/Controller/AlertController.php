<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeAlertTypeRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alertas', name: 'alert_')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    private const PERIOD_PRESETS = ['today' => ['label' => 'Hoje', 'days' => 0], 'yesterday' => ['label' => 'Ontem', 'days' => 1], 'week' => ['label' => '7 dias', 'days' => 7], 'month' => ['label' => '30 dias', 'days' => 30], 'six_months' => ['label' => '6 meses', 'days' => 182], 'year' => ['label' => '1 ano', 'days' => 365]];

    public function __construct(private readonly TenantContext $tenantContext, private readonly WazeAlertRepository $alertRepo, private readonly WazeAlertTypeRepository $alertTypeRepo) {}

    private function filtersFromRequest(Request $request): array
    {
        $dateFrom = $request->query->get('dateFrom') ?: null; $dateTo = $request->query->get('dateTo') ?: null; $period = $request->query->get('period') ?: null;
        if ($period && isset(self::PERIOD_PRESETS[$period]) && !$dateFrom && !$dateTo) { $tz = new \DateTimeZone('America/Sao_Paulo'); $today = new \DateTimeImmutable('now', $tz); if ($period === 'yesterday') $dateFrom = $dateTo = $today->modify('-1 day')->format('Y-m-d'); else { $dateFrom = $today->modify('-' . self::PERIOD_PRESETS[$period]['days'] . ' days')->format('Y-m-d'); $dateTo = $today->format('Y-m-d'); } }
        return ['type' => $request->query->get('type') ?: null, 'subtype' => $request->query->get('subtype') ?: null, 'city' => $request->query->get('city') ?: null, 'street' => $request->query->get('street') ?: null, 'excludeStreet' => $request->query->get('excludeStreet') ?: null, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo];
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner(); $locale = $request->getLocale() ?: 'pt'; $filters = $this->filtersFromRequest($request); $page = max(1, (int) $request->query->get('page', 1)); $result = $this->alertRepo->findFilteredByPartner($partner, $filters, $page, 30); $typesMap = $this->alertTypeRepo->getTypesMap($locale); $subtypesMap = $this->alertTypeRepo->getSubtypesMap($locale); $rows = $this->alertRepo->countBySubtypeFiltered($partner, $filters, 10); $bySubtype = array_map(static fn(array $row) => ['label' => $row['subtype'] ?: ($typesMap[$row['type']] ?? $row['type']), 'total' => (int) $row['total']], $rows);
        return $this->render('alert/index.html.twig', ['partner' => $partner, 'alerts' => $result['items'], 'total' => $result['total'], 'page' => $page, 'pages' => $result['pages'], 'types' => $this->alertRepo->findDistinctTypes($partner), 'subtypes' => $this->alertRepo->findDistinctSubtypes($partner, $filters['type']), 'cities' => $this->alertRepo->findDistinctCities($partner), 'streets' => $this->alertRepo->findDistinctStreets($partner), 'bySubtype' => $bySubtype, 'byConfidence' => $this->alertRepo->countByConfidenceFiltered($partner, $filters), 'byDay' => $this->alertRepo->countByDayFiltered($partner, $filters), 'byHour' => $this->alertRepo->countByHourOfDayFiltered($partner, $filters), 'byHourTrend' => $this->alertRepo->countByHourOfDayFiltered($partner, $filters), 'trendType' => 'hour', 'byWeekday' => $this->alertRepo->countByWeekdayFiltered($partner, $filters), 'topStreets' => $this->alertRepo->topStreetsFiltered($partner, $filters, 10), 'hotspots' => $this->alertRepo->findHotspotsFiltered($partner, $filters, 15), 'mapAlerts' => $this->alertRepo->findForMapFiltered($partner, $filters, 500), 'type' => $filters['type'], 'subtype' => $filters['subtype'], 'city' => $filters['city'], 'street' => $filters['street'], 'excludeStreet' => $filters['excludeStreet'], 'period' => $request->query->get('period'), 'periods' => self::PERIOD_PRESETS, 'dateFrom' => $filters['dateFrom'], 'dateTo' => $filters['dateTo'], 'typesMap' => $typesMap, 'subtypesMap' => $subtypesMap]);
    }

    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner(); $filters = $this->filtersFromRequest($request); $alerts = $this->alertRepo->findAllFilteredByPartnerForExport($partner, $filters); $output = fopen('php://temp', 'w+'); fputcsv($output, ['ID', 'Tipo', 'Subtipo', 'Via', 'Cidade', 'Publicado', 'Confiança', 'Curtidas', 'Latitude', 'Longitude'], ';'); foreach ($alerts as $alert) fputcsv($output, [$alert->getId(), $alert->getType(), $alert->getSubtype(), $alert->getStreet(), $alert->getCity(), $alert->getPubMillis(), $alert->getConfidence(), $alert->getNThumbsUp(), $alert->getLatitude(), $alert->getLongitude()], ';'); rewind($output); $content = stream_get_contents($output); fclose($output); $response = new Response("\xEF\xBB\xBF" . $content); $response->headers->set('Content-Type', 'text/csv; charset=UTF-8'); $response->headers->set('Content-Disposition', 'attachment; filename=alertas.csv'); return $response;
    }

    #[Route('/ao-vivo', name: 'live', methods: ['GET'])]
    public function live(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner(); $locale = $request->getLocale() ?: 'pt'; $alerts = $this->alertRepo->findActiveByPartner($partner, 10); $regions = []; foreach ($alerts as $alert) { $city = $alert->getCity() ?: 'Sem cidade'; $regions[$city] = ($regions[$city] ?? 0) + 1; } arsort($regions); $regionRows = array_map(static fn($city, $count) => ['city' => $city, 'count' => $count], array_keys($regions), array_values($regions)); dump(['rota' => 'alert_live', 'total_alertas_ativos' => count($alerts), 'regioes' => $regionRows, 'ids' => array_map(static fn($alert) => $alert->getId(), $alerts), 'pub_millis' => array_map(static fn($alert) => $alert->getPubMillis(), $alerts), 'agora_millis' => time() * 1000, 'limite_millis' => (time() - 600) * 1000]); return $this->render('alert/live.html.twig', ['partner' => $partner, 'regions' => $regionRows, 'alerts' => $alerts, 'hours' => 0, 'total' => count($alerts), 'typesMap' => $this->alertTypeRepo->getTypesMap($locale), 'subtypesMap' => $this->alertTypeRepo->getSubtypesMap($locale)]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $partner = $this->tenantContext->requirePartner(); $alert = $this->alertRepo->findOneByPartner($id, $partner); if (!$alert) throw $this->createNotFoundException('Alerta não encontrado.'); return $this->render('alert/show.html.twig', ['partner' => $partner, 'alert' => $alert, 'typesMap' => $this->alertTypeRepo->getTypesMap('pt'), 'subtypesMap' => $this->alertTypeRepo->getSubtypesMap('pt')]);
    }
}
