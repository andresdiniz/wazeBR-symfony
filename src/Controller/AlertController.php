<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeAlertTypeRepository;
use App\Service\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
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
    private function filtersFromRequest(Request $request): array { $filters = ['type' => $request->query->get('type') ?: null, 'subtype' => $request->query->get('subtype') ?: null, 'city' => $request->query->get('city') ?: null, 'street' => $request->query->get('street') ?: null, 'excludeStreet' => $request->query->get('excludeStreet') ?: null, 'dateFrom' => $request->query->get('dateFrom') ?: null, 'dateTo' => $request->query->get('dateTo') ?: null]; $period = $request->query->get('period'); if ($period && isset(self::PERIOD_PRESETS[$period]) && !$filters['dateFrom'] && !$filters['dateTo']) { $timezone = new DateTimeZone('America/Sao_Paulo'); $now = new DateTimeImmutable('now', $timezone); $days = self::PERIOD_PRESETS[$period]['days']; $start = $days === 0 ? $now->setTime(0, 0, 0) : $now->modify(sprintf('-%d days', $days))->setTime(0, 0, 0); $end = $period === 'yesterday' ? $now->modify('-1 day')->setTime(23, 59, 59) : $now->setTime(23, 59, 59); $filters['dateFrom'] = $start->format('Y-m-d H:i:s'); $filters['dateTo'] = $end->format('Y-m-d H:i:s'); } return $filters; }
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response { $partner = $this->tenantContext->requirePartner(); $locale = $request->getLocale() ?: 'pt'; $filters = $this->filtersFromRequest($request); $period = $request->query->get('period'); $hourlyTrend = in_array($period, ['today', 'yesterday'], true); $result = $this->alertRepo->findFilteredByPartner($partner, $filters, max(1, (int) $request->query->get('page', 1)), 30); $bySubtype = array_map(static fn (array $row): array => ['label' => ($row['subtype'] ?: 'Sem subtipo'), 'total' => (int) $row['total']], $this->alertRepo->countBySubtypeFiltered($partner, $filters, 10)); $byDay = $this->alertRepo->countByDayFiltered($partner, $filters); $byHour = $this->alertRepo->countByHourOfDayFiltered($partner, $filters); return $this->render('alert/index.html.twig', ['partner' => $partner, 'alerts' => $result['items'], 'total' => $result['total'], 'page' => max(1, (int) $request->query->get('page', 1)), 'pages' => $result['pages'], 'types' => $this->alertRepo->findDistinctTypes($partner), 'subtypes' => $this->alertRepo->findDistinctSubtypes($partner, $filters['type']), 'cities' => $this->alertRepo->findDistinctCities($partner), 'streets' => $this->alertRepo->findDistinctStreets($partner), 'bySubtype' => $bySubtype, 'byConfidence' => $this->alertRepo->countByConfidenceFiltered($partner, $filters), 'byDay' => $byDay, 'byHour' => $byHour, 'byHourTrend' => $hourlyTrend ? $byHour : $byDay, 'trendType' => $hourlyTrend ? 'hour' : 'day', 'byWeekday' => $this->alertRepo->countByWeekdayFiltered($partner, $filters), 'topStreets' => $this->alertRepo->topStreetsFiltered($partner, $filters, 10), 'hotspots' => $this->alertRepo->findHotspotsFiltered($partner, $filters, 15), 'mapAlerts' => $this->alertRepo->findForMapFiltered($partner, $filters, 500), 'type' => $filters['type'], 'subtype' => $filters['subtype'], 'city' => $filters['city'], 'street' => $filters['street'], 'excludeStreet' => $filters['excludeStreet'], 'period' => $period, 'periods' => self::PERIOD_PRESETS, 'dateFrom' => $filters['dateFrom'], 'dateTo' => $filters['dateTo'], 'typesMap' => $this->alertTypeRepo->getTypesMap($locale), 'subtypesMap' => $this->alertTypeRepo->getSubtypesMap($locale)]); }
    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response { $partner = $this->tenantContext->requirePartner(); $alerts = $this->alertRepo->findAllFilteredByPartnerForExport($partner, $this->filtersFromRequest($request)); $output = fopen('php://temp', 'w+'); fputcsv($output, ['ID', 'Tipo', 'Subtipo', 'Via', 'Cidade', 'Publicado', 'Confiança', 'Curtidas', 'Latitude', 'Longitude'], ';'); foreach ($alerts as $alert) fputcsv($output, [$alert->getId(), $alert->getType(), $alert->getSubtype(), $alert->getStreet(), $alert->getCity(), $alert->getPubMillis(), $alert->getConfidence(), $alert->getNThumbsUp(), $alert->getLatitude(), $alert->getLongitude()], ';'); rewind($output); $content = stream_get_contents($output); fclose($output); $response = new Response("\xEF\xBB\xBF" . $content); $response->headers->set('Content-Type', 'text/csv; charset=UTF-8'); $response->headers->set('Content-Disposition', 'attachment; filename=alertas.csv'); return $response; }
    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): Response { $partner = $this->tenantContext->requirePartner(); $alert = $this->alertRepo->findOneByPartner($id, $partner); if (!$alert) throw $this->createNotFoundException('Alerta não encontrado.'); return $this->render('alert/show.html.twig', ['partner' => $partner, 'alert' => $alert, 'typesMap' => $this->alertTypeRepo->getTypesMap('pt'), 'subtypesMap' => $this->alertTypeRepo->getSubtypesMap('pt')]); }
}
