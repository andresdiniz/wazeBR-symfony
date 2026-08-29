<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeTvtRouteRepository;
use App\Repository\WazeTvtSnapshotRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/rotas', name: 'route_admin_')]
#[IsGranted('ROLE_USER')]
class RouteAdminController extends AbstractController
{
    private const PERIOD_PRESETS = [
        'last8h'     => ['label' => 'Últimas 8h', 'hours' => 8],
        'today'      => ['label' => 'Hoje',       'days' => 0],
        'yesterday'  => ['label' => 'Ontem',      'days' => 1],
        'week'       => ['label' => '7 dias',     'days' => 7],
        'month'      => ['label' => '30 dias',    'days' => 30],
        'six_months' => ['label' => '6 meses',    'days' => 182],
        'year'       => ['label' => '1 ano',      'days' => 365],
    ];

    private const WEEKDAY_LABELS = [1 => 'Dom', 2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb'];
    private const WEEKDAY_LABELS_FULL = [
        1 => 'Domingo', 2 => 'Segunda', 3 => 'Terça', 4 => 'Quarta',
        5 => 'Quinta', 6 => 'Sexta', 7 => 'Sábado',
    ];

    private const JAM_COLORS = ['#437a22', '#65a30d', '#d19900', '#da7101', '#a12c7b', '#dc2626'];
    private const MAX_DISPLAY_LIMIT = 3000;
    private const MAX_EXPORT_ROWS = 20000;

    public function __construct(
        private readonly TenantContext             $tenantContext,
        private readonly WazeTvtRouteRepository    $tvtRouteRepo,
        private readonly WazeTvtSnapshotRepository $snapshotRepo,
    ) {}

    // ─── Listagem ─────────────────────────────────────────────────────────────

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner   = $this->tenantContext->requirePartner();
        $jamFilter = $request->query->get('jam');

        $routes = $this->tvtRouteRepo->findTvtByPartner(
            $partner,
            $jamFilter !== null && $jamFilter !== '' ? (int) $jamFilter : null,
        );

        $lastSnapshot = $this->snapshotRepo->findLatestByPartner($partner);

        $total  = count($routes);
        $levels = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $totalDelaySec  = 0;
        $routesWithDelay = 0;

        foreach ($routes as $r) {
            $lv = $r->getJamLevel() ?? 0;
            $levels[$lv] = ($levels[$lv] ?? 0) + 1;
            $delay = $r->getDelaySeconds();
            if ($delay !== null) {
                $totalDelaySec  += $delay;
                $routesWithDelay++;
            }
        }

        $avgDelaySec = $routesWithDelay > 0 ? (int) ($totalDelaySec / $routesWithDelay) : 0;
        $congested   = ($levels[3] ?? 0) + ($levels[4] ?? 0) + ($levels[5] ?? 0);

        return $this->render('route/index.html.twig', [
            'partner'      => $partner,
            'routes'       => $routes,
            'lastSnapshot' => $lastSnapshot,
            'kpi' => [
                'total'       => $total,
                'congested'   => $congested,
                'avgDelaySec' => $avgDelaySec,
                'levels'      => $levels,
            ],
            'jamFilter' => $jamFilter,
        ]);
    }

    // ─── Histórico de uma rota (com gráficos e mapa) ────────────────────────

    #[Route('/historico/{wazeRouteId}', name: 'history')]
    public function history(string $wazeRouteId, Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();

        $latest = $this->tvtRouteRepo->findLatestByWazeId($partner, $wazeRouteId);
        if (!$latest) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        [$filters, $period] = $this->resolveFilters($request);

        // Limite reduzido para 30 (rápido)
        $limit = (int) $request->query->get('limit', 30);
        $limit = max(5, min($limit, 200));

        // Histórico (objetos) para o gráfico e tabela
        $history = $this->tvtRouteRepo->findHistoryByWazeIdFiltered($partner, $wazeRouteId, $filters, $limit);
        $totalInRange = $this->tvtRouteRepo->countHistoryFiltered($partner, $wazeRouteId, $filters);
        $byJamLevel   = $this->tvtRouteRepo->countByJamLevelForRoute($partner, $wazeRouteId, $filters);
        $weekdayHour  = $this->tvtRouteRepo->weekdayHourProfile($partner, $wazeRouteId, $filters);

        // ─── KPIs dinâmicos ──────────────────────────────────────────────
        $times = array_filter(array_column($history, 'getTime'));
        $delays = array_map(fn($r) => $r->getDelaySeconds(), $history);
        $delays = array_filter($delays, fn($d) => $d !== null);

        $kpis = [
            'total' => $totalInRange,
            'avgTime' => !empty($times) ? round(array_sum($times) / count($times) / 60, 1) : null,
            'minTime' => !empty($times) ? round(min($times) / 60, 1) : null,
            'maxTime' => !empty($times) ? round(max($times) / 60, 1) : null,
            'avgDelay' => !empty($delays) ? round(array_sum($delays) / count($delays) / 60, 1) : null,
            'maxDelay' => !empty($delays) ? round(max($delays) / 60, 1) : null,
            'pctOnTime' => !empty($delays) ? round((count(array_filter($delays, fn($d) => $d <= 0)) / count($delays)) * 100, 1) : null,
            'jamDist' => $byJamLevel,
            'latest' => $latest,
            'sazonal' => $this->calculateSazonalKpis($partner, $wazeRouteId, $latest),
        ];

        return $this->render('route/history.html.twig', [
            'partner'       => $partner,
            'latest'        => $latest,
            'history'       => $history,
            'wazeRouteId'   => $wazeRouteId,
            'period'        => $period,
            'periods'       => self::PERIOD_PRESETS,
            'dateFrom'      => $filters['dateFrom'],
            'dateTo'        => $filters['dateTo'],
            'minJam'        => $filters['minJam'],
            'limit'         => $limit,
            'totalInRange'  => $totalInRange,
            'byJamLevel'    => $byJamLevel,
            'jamColors'     => self::JAM_COLORS,
            'heatmap'       => $this->buildHeatmapGrid($weekdayHour),
            'hourlyProfile' => $this->buildHourlyProfile($weekdayHour),
            'weekdayLabels' => self::WEEKDAY_LABELS,
            'weekdayLabelsFull' => self::WEEKDAY_LABELS_FULL,
            'kpis'          => $kpis,
        ]);
    }

    // ─── Exportação CSV ──────────────────────────────────────────────────────

    #[Route('/historico/{wazeRouteId}/exportar', name: 'history_export')]
    public function historyExport(string $wazeRouteId, Request $request): StreamedResponse
    {
        $partner = $this->tenantContext->requirePartner();

        $latest = $this->tvtRouteRepo->findLatestByWazeId($partner, $wazeRouteId);
        if (!$latest) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        [$filters] = $this->resolveFilters($request);

        $rows = $this->tvtRouteRepo->findHistoryByWazeIdFiltered($partner, $wazeRouteId, $filters, self::MAX_EXPORT_ROWS);
        $tz   = new \DateTimeZone('America/Sao_Paulo');

        $response = new StreamedResponse(function () use ($rows, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM

            fputcsv($out, [
                'coletado_em', 'dia_semana', 'nome', 'de', 'para',
                'jam_level', 'tempo_atual_seg', 'tempo_atual_min',
                'tempo_historico_seg', 'tempo_historico_min',
                'atraso_seg', 'atraso_min', 'extensao_km',
            ], ';');

            foreach ($rows as $r) {
                $collected = $r->getSnapshot()?->getCollectedAt();
                $localTime = $collected ? \DateTimeImmutable::createFromInterface($collected)->setTimezone($tz) : null;

                $time  = $r->getTime();
                $hist  = $r->getHistoricTime();
                $delay = ($time !== null && $hist !== null) ? $time - $hist : null;

                fputcsv($out, [
                    $localTime?->format('Y-m-d H:i:s') ?? '',
                    $localTime ? (self::WEEKDAY_LABELS_FULL[(int) $localTime->format('w') + 1] ?? '') : '',
                    $r->getName() ?? '',
                    $r->getFromName() ?? '',
                    $r->getToName() ?? '',
                    $r->getJamLevel() ?? '',
                    $time ?? '',
                    $time !== null ? round($time / 60, 1) : '',
                    $hist ?? '',
                    $hist !== null ? round($hist / 60, 1) : '',
                    $delay ?? '',
                    $delay !== null ? round($delay / 60, 1) : '',
                    $r->getLength() !== null ? round($r->getLength() / 1000, 3) : '',
                ], ';');
            }

            fclose($out);
        });

        $filename = sprintf(
            'historico_rota_%s_%s.csv',
            preg_replace('/[^a-zA-Z0-9_-]/', '_', $wazeRouteId),
            (new \DateTimeImmutable())->format('Ymd_His'),
        );

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolveFilters(Request $request): array
    {
        $period   = $request->query->get('period') ?: null;
        $dateFrom = $request->query->get('dateFrom') ?: null;
        $dateTo   = $request->query->get('dateTo') ?: null;
        $minJam   = $request->query->get('minJam');
        $minJam   = ($minJam !== null && $minJam !== '') ? max(0, min(5, (int) $minJam)) : null;

        if (!$dateFrom && !$dateTo && !$period) {
            $period = 'last8h';
        }

        $presets = self::PERIOD_PRESETS;

        if ($period && isset($presets[$period]) && !$dateFrom && !$dateTo) {
            $tz    = new \DateTimeZone('America/Sao_Paulo');
            $now   = new \DateTimeImmutable('now', $tz);

            if ($period === 'last8h') {
                $dateFrom = $now->modify('-8 hours')->format('Y-m-d H:i:s');
                $dateTo   = $now->format('Y-m-d H:i:s');
            } elseif ($period === 'yesterday') {
                $dateFrom = $dateTo = $now->modify('-1 day')->format('Y-m-d');
            } else {
                $dateFrom = $now->modify('-' . $presets[$period]['days'] . ' days')->format('Y-m-d');
                $dateTo   = $now->format('Y-m-d');
            }
        }

        if (!$dateFrom && !$dateTo) {
            $tz = new \DateTimeZone('America/Sao_Paulo');
            $now = new \DateTimeImmutable('now', $tz);
            $dateFrom = $now->modify('-30 days')->format('Y-m-d');
            $dateTo   = $now->format('Y-m-d');
        }

        return [compact('dateFrom', 'dateTo', 'minJam'), $period];
    }

    /**
     * Calcula KPIs sazonais baseados nos últimos 7 dias, agrupados por hora.
     */
    private function calculateSazonalKpis(\App\Entity\Partner $partner, string $wazeRouteId, \App\Entity\WazeTvtRoute $latest): array
    {
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $now = new \DateTimeImmutable('now', $tz);
        $sevenDaysAgo = $now->modify('-7 days');

        // Busca dados dos últimos 7 dias (sem filtros adicionais)
        $historySevenDays = $this->tvtRouteRepo->findHistoryByWazeIdFiltered(
            $partner,
            $wazeRouteId,
            ['dateFrom' => $sevenDaysAgo->format('Y-m-d'), 'dateTo' => $now->format('Y-m-d')],
            5000
        );

        // Agrupa por hora
        $hourlyStats = [];
        foreach ($historySevenDays as $r) {
            $collected = $r->getSnapshot()->getCollectedAt();
            if (!$collected) continue;
            $hour = (int) $collected->setTimezone($tz)->format('H');
            if (!isset($hourlyStats[$hour])) {
                $hourlyStats[$hour] = ['times' => [], 'histTimes' => []];
            }
            if ($r->getTime() !== null) {
                $hourlyStats[$hour]['times'][] = $r->getTime();
            }
            if ($r->getHistoricTime() !== null) {
                $hourlyStats[$hour]['histTimes'][] = $r->getHistoricTime();
            }
        }

        $hourlyAvg = [];
        foreach ($hourlyStats as $hour => $data) {
            $hourlyAvg[$hour] = [
                'avgTime' => !empty($data['times']) ? round(array_sum($data['times']) / count($data['times']) / 60, 1) : null,
                'avgHist' => !empty($data['histTimes']) ? round(array_sum($data['histTimes']) / count($data['histTimes']) / 60, 1) : null,
                'count' => count($data['times']),
            ];
        }

        $currentHour = (int) $now->format('H');
        $avgCurrentHour = $hourlyAvg[$currentHour] ?? null;
        $currentTime = $latest->getTime();
        $currentHist = $latest->getHistoricTime();

        $peakHour = null;
        $bestHour = null;
        $maxAvg = -INF;
        $minAvg = INF;
        foreach ($hourlyAvg as $hour => $data) {
            if ($data['avgTime'] !== null && $data['avgTime'] > $maxAvg) {
                $maxAvg = $data['avgTime'];
                $peakHour = $hour;
            }
            if ($data['avgTime'] !== null && $data['avgTime'] < $minAvg) {
                $minAvg = $data['avgTime'];
                $bestHour = $hour;
            }
        }

        return [
            'hour' => $currentHour,
            'avgTime' => $avgCurrentHour ? $avgCurrentHour['avgTime'] : null,
            'avgHist' => $avgCurrentHour ? $avgCurrentHour['avgHist'] : null,
            'currentTime' => $currentTime !== null ? round($currentTime / 60, 1) : null,
            'currentHist' => $currentHist !== null ? round($currentHist / 60, 1) : null,
            'delayVsAvg' => ($currentTime !== null && $avgCurrentHour && $avgCurrentHour['avgTime'] !== null)
                ? round(($currentTime / 60) - $avgCurrentHour['avgTime'], 1)
                : null,
            'sampleCount' => $avgCurrentHour ? $avgCurrentHour['count'] : 0,
            'peakHour' => $peakHour !== null ? sprintf('%02d:00', $peakHour) : null,
            'bestHour' => $bestHour !== null ? sprintf('%02d:00', $bestHour) : null,
            'peakAvg' => $peakHour !== null ? $maxAvg : null,
            'bestAvg' => $bestHour !== null ? $minAvg : null,
        ];
    }

    private function buildHeatmapGrid(array $rows): array
    {
        $grid = [];
        foreach (range(1, 7) as $wd) {
            foreach (range(0, 22, 2) as $bucket) {
                $grid[$wd][$bucket] = null;
            }
        }

        foreach ($rows as $r) {
            if (!isset($grid[$r['wd']])) {
                continue;
            }
            $grid[$r['wd']][$r['bucket']] = [
                'avgDelayMin' => round($r['avgDelay'] / 60, 1),
                'avgTimeMin'  => round($r['avgTime'] / 60, 1),
                'avgJam'      => round($r['avgJam'], 1),
                'color'       => self::JAM_COLORS[max(0, min(5, (int) round($r['avgJam'])))],
                'total'       => $r['total'],
            ];
        }

        return $grid;
    }

    private function buildHourlyProfile(array $rows): array
    {
        $acc = [];
        foreach (range(0, 22, 2) as $bucket) {
            $acc[$bucket] = ['time' => 0.0, 'hist' => 0.0, 'total' => 0];
        }

        foreach ($rows as $r) {
            $acc[$r['bucket']]['time']  += $r['avgTime'] * $r['total'];
            $acc[$r['bucket']]['hist']  += $r['avgHist'] * $r['total'];
            $acc[$r['bucket']]['total'] += $r['total'];
        }

        $profile = [];
        foreach ($acc as $bucket => $a) {
            $profile[] = [
                'bucket'     => $bucket,
                'avgTimeMin' => $a['total'] > 0 ? round($a['time'] / $a['total'] / 60, 1) : null,
                'avgHistMin' => $a['total'] > 0 ? round($a['hist'] / $a['total'] / 60, 1) : null,
                'total'      => $a['total'],
            ];
        }

        return $profile;
    }
}
