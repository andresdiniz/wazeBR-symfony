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
    /** Períodos fixos do filtro (além de datas manuais) — mesmo padrão de AlertController. */
    private const PERIOD_PRESETS = [
        'today'      => ['label' => 'Hoje',    'days' => 0],
        'yesterday'  => ['label' => 'Ontem',   'days' => 1],
        'week'       => ['label' => '7 dias',  'days' => 7],
        'month'      => ['label' => '30 dias', 'days' => 30],
        'six_months' => ['label' => '6 meses', 'days' => 182],
        'year'       => ['label' => '1 ano',   'days' => 365],
    ];

    /** Nomes (em português) dos dias da semana retornados por DAYOFWEEK (1=domingo..7=sábado). */
    private const WEEKDAY_LABELS = [1 => 'Dom', 2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb'];
    private const WEEKDAY_LABELS_FULL = [
        1 => 'Domingo', 2 => 'Segunda', 3 => 'Terça', 4 => 'Quarta',
        5 => 'Quinta', 6 => 'Sexta', 7 => 'Sábado',
    ];

    /** Cores de congestionamento (mesma paleta usada no mapa/badges jam-0..5 do template). */
    private const JAM_COLORS = ['#437a22', '#65a30d', '#d19900', '#da7101', '#a12c7b', '#dc2626'];

    /** Teto de segurança para a listagem/gráfico de linha (não afeta heatmap nem exportação). */
    private const MAX_DISPLAY_LIMIT = 3000;

    /** Teto de segurança para exportação (evita travar em rotas com muitos anos de coleta). */
    private const MAX_EXPORT_ROWS = 20000;

    public function __construct(
        private readonly TenantContext              $tenantContext,
        private readonly WazeTvtRouteRepository     $tvtRouteRepo,
        private readonly WazeTvtSnapshotRepository  $snapshotRepo,
    ) {}

    // ─── Listagem / histórico TVT ─────────────────────────────────────────────

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

        // Build routesJs: JSON array consumed by the Leaflet map at line 303
        $routesJs = array_map(static function ($r): array {
            $line = $r->getLine();
            // line is stored as JSON string or array
            $lineDecoded = is_string($line) ? json_decode($line, true) : $line;

            return [
                'id'          => $r->getId(),
                'wazeRouteId' => $r->getWazeRouteId(),
                'name'        => $r->getName(),
                'fromName'    => $r->getFromName(),
                'toName'      => $r->getToName(),
                'jamLevel'    => $r->getJamLevel() ?? 0,
                'time'        => $r->getTime(),
                'historicTime'=> $r->getHistoricTime(),
                'length'      => $r->getLength(),
                'line'        => $lineDecoded ?? [],
            ];
        }, $routes);

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
            'jamFilter'  => $jamFilter,
            'routesJs'   => $routesJs,
        ]);
    }

    // ─── Série histórica de uma rota TVT ──────────────────────────────────────

    #[Route('/historico/{wazeRouteId}', name: 'history')]
    public function history(string $wazeRouteId, Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();

        $latest = $this->tvtRouteRepo->findLatestByWazeId($partner, $wazeRouteId);
        if (!$latest) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        [$filters, $period] = $this->resolveFilters($request);

        $limit = (int) $request->query->get('limit', 300);
        $limit = max(10, min($limit, self::MAX_DISPLAY_LIMIT));

        $history      = $this->tvtRouteRepo->findHistoryByWazeIdFiltered($partner, $wazeRouteId, $filters, $limit);
        $totalInRange = $this->tvtRouteRepo->countHistoryFiltered($partner, $wazeRouteId, $filters);
        $byJamLevel   = $this->tvtRouteRepo->countByJamLevelForRoute($partner, $wazeRouteId, $filters);
        $weekdayHour  = $this->tvtRouteRepo->weekdayHourProfile($partner, $wazeRouteId, $filters);

        return $this->render('route/history.html.twig', [
            'partner'       => $partner,
            'latest'        => $latest,
            'history'       => $history,
            'wazeRouteId'   => $wazeRouteId,

            // filtros
            'period'        => $period,
            'periods'       => self::PERIOD_PRESETS,
            'dateFrom'      => $filters['dateFrom'],
            'dateTo'        => $filters['dateTo'],
            'minJam'        => $filters['minJam'],
            'limit'         => $limit,
            'totalInRange'  => $totalInRange,

            // gráficos
            'byJamLevel'    => $byJamLevel,
            'jamColors'     => self::JAM_COLORS,
            'heatmap'           => $this->buildHeatmapGrid($weekdayHour),
            'hourlyProfile'     => $this->buildHourlyProfile($weekdayHour),
            'weekdayLabels'     => self::WEEKDAY_LABELS,
            'weekdayLabelsFull' => self::WEEKDAY_LABELS_FULL,
        ]);
    }

    /** Exporta o histórico filtrado da rota em CSV (padrão Excel BR: `;` + BOM UTF-8). */
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
            fwrite($out, "\xEF\xBB\xBF"); // BOM p/ o Excel reconhecer UTF-8

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

    // ─── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Resolve os filtros de data/congestionamento da querystring, preenchendo
     * dateFrom/dateTo a partir de um período fixo (?period=) quando não há
     * datas manuais. Padrão: 30 dias (o suficiente para o heatmap semana×hora
     * já mostrar um padrão minimamente estável).
     *
     * @return array{0:array{dateFrom:?string,dateTo:?string,minJam:?int},1:?string}
     */
    private function resolveFilters(Request $request): array
    {
        $period   = $request->query->get('period') ?: null;
        $dateFrom = $request->query->get('dateFrom') ?: null;
        $dateTo   = $request->query->get('dateTo') ?: null;
        $minJam   = $request->query->get('minJam');
        $minJam   = ($minJam !== null && $minJam !== '') ? max(0, min(5, (int) $minJam)) : null;

        if (!$dateFrom && !$dateTo && !$period) {
            $period = 'month';
        }

        if ($period && isset(self::PERIOD_PRESETS[$period]) && !$dateFrom && !$dateTo) {
            $tz    = new \DateTimeZone('America/Sao_Paulo');
            $today = new \DateTimeImmutable('now', $tz);

            if ($period === 'yesterday') {
                $dateFrom = $dateTo = $today->modify('-1 day')->format('Y-m-d');
            } else {
                $dateFrom = $today->modify('-' . self::PERIOD_PRESETS[$period]['days'] . ' days')->format('Y-m-d');
                $dateTo   = $today->format('Y-m-d');
            }
        }

        return [compact('dateFrom', 'dateTo', 'minJam'), $period];
    }

    /**
     * Monta a grade completa 7 (domingo..sábado) × 12 (faixas de 2h) a partir
     * das linhas agregadas pelo repository, preenchendo com null onde não há
     * dados — para o template não precisar tratar "buraco" na grade.
     *
     * @param list<array{wd:int,bucket:int,avgTime:float,avgHist:float,avgDelay:float,avgJam:float,total:int}> $rows
     */
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

    /**
     * Agrega o weekdayHourProfile (que já vem por dia×hora) só por faixa de
     * 2h, ponderando pela contagem de cada dia — dá o "perfil típico do dia"
     * da rota ao longo do período filtrado, sem precisar de outra consulta.
     *
     * @param list<array{wd:int,bucket:int,avgTime:float,avgHist:float,avgDelay:float,avgJam:float,total:int}> $rows
     * @return list<array{bucket:int,avgTimeMin:?float,avgHistMin:?float,total:int}>
     */
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
