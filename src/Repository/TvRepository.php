<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Agregação para a tela de TV / wallboard (/tv).
 *
 * Histórico de mudanças:
 *  - 1.1: redução de round-trips (loadAlerts, loadJams, loadToday, loadFetchStatus)
 *  - 1.2: loadHydro via window function (elimina N+1)
 *  - 1.4: loadMap ordena por severidade / loadRain usa média / loadToday parametriza tz
 *  - Novos dados aditivos: alerts.oldestAt, alerts.lastCriticalAt, alerts.byHour,
 *    alerts.topStreets, alerts.topCities, today.sameWeekday, today.trendVsLastWeek,
 *    rain.stations
 *  - Rotas TVT: compara time atual vs. historic_time de 30 dias
 *  - Fix: alias `current_time` renomeado (CURRENT_TIME é função built-in no MariaDB)
 */
final class TvRepository
{
    private const CRITICAL_ALERT_TYPES = [
        'ACCIDENT',
        'ROAD_CLOSED',
        'HAZARD_WEATHER_FLOOD',
        'WEATHERHAZARD',
    ];

    private const ALERTS_MAP_WINDOW_HOURS = 2;
    private const JAMS_MAP_MIN_LEVEL      = 4;

    /** Ordem de severidade para o mapa (menor = mais crítico). */
    private const MAP_TYPE_PRIORITY = [
        'ROAD_CLOSED'          => 1,
        'HAZARD_WEATHER_FLOOD' => 2,
        'ACCIDENT'             => 3,
        'WEATHERHAZARD'        => 4,
        'HAZARD'               => 5,
        'POLICE'               => 6,
        'CONSTRUCTION'         => 7,
    ];

    /** Janela para considerar um snapshot "atual" (horas). */
    private const ROUTES_CURRENT_WINDOW_HOURS = 2;

    /** Janela histórica para a média (dias). */
    private const ROUTES_HISTORY_DAYS = 30;

    /** Threshold mínimo de slowness% para entrar como "relevante". */
    private const ROUTES_SLOWNESS_THRESHOLD = 10.0;

    /** Quantas rotas mostrar. */
    private const ROUTES_LIMIT = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly ParameterBagInterface $params,
    ) {
    }

    private function appTimezone(): string
    {
        try {
            return (string) $this->params->get('app_timezone');
        } catch (\Throwable) {
            return 'America/Sao_Paulo';
        }
    }

    /** @return array<string,mixed> */
    public function getWallboard(?Partner $partner): array
    {
        $alerts   = $this->loadAlerts($partner);
        $jams     = $this->loadJams($partner);
        $feed     = $this->buildFeed($alerts['recent'], $jams['recent']);
        $map      = $this->loadMap($partner);
        $hydro    = $this->loadHydro($partner);
        $routes   = $this->loadRoutes($partner);
        $rain     = $this->loadRain($partner);
        $weather  = $this->loadWeather($partner);
        $today    = $this->loadToday($partner);
        $fetch    = $this->loadFetchStatus($partner);
        $cameras  = $this->loadCameras($partner);

        $status = $this->computeStatus($alerts, $hydro, $rain);

        return [
            'status'      => $status,
            'alerts'      => $alerts,
            'jams'        => $jams,
            'feed'        => $feed,
            'map'         => $map,
            'hydro'       => $hydro,
            'routes'      => $routes,
            'rain'        => $rain,
            'weather'     => $weather,
            'today'       => $today,
            'fetch'       => $fetch,
            'cameras'     => $cameras,
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function computeStatus(array $alerts, array $hydro, array $rain): array
    {
        $level   = 'normal';
        $reasons = [];

        if ($hydro['risk']['overflow'] > 0) {
            $level     = 'critical';
            $reasons[] = $hydro['risk']['overflow'] . ' rio(s) em transbordamento';
        } elseif ($hydro['risk']['alert'] > 0) {
            $level     = 'critical';
            $reasons[] = $hydro['risk']['alert'] . ' rio(s) em alerta';
        } elseif ($hydro['risk']['attention'] > 0) {
            $level     = 'attention';
            $reasons[] = $hydro['risk']['attention'] . ' rio(s) em atenção';
        }

        $critLast1h = $alerts['last1h']['critical'] ?? 0;
        if ($critLast1h >= 3) {
            $level     = 'critical';
            $reasons[] = "{$critLast1h} alertas críticos na última hora";
        } elseif ($critLast1h >= 1 && $level === 'normal') {
            $level     = 'attention';
            $reasons[] = "{$critLast1h} alerta(s) crítico(s) na última hora";
        }

        if (($rain['lastHour'] ?? 0) > 20) {
            $level     = 'critical';
            $reasons[] = 'Chuva forte: ' . number_format($rain['lastHour'], 1, ',', '.') . ' mm/h';
        } elseif (($rain['lastHour'] ?? 0) > 10 && $level === 'normal') {
            $level     = 'attention';
            $reasons[] = 'Chuva moderada na última hora';
        }

        return [
            'level'   => $level,
            'label'   => match ($level) {
                'critical'  => 'OPERAÇÃO CRÍTICA',
                'attention' => 'ATENÇÃO',
                default     => 'OPERAÇÃO NORMAL',
            },
            'reasons' => $reasons,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadAlerts(?Partner $partner): array
    {
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $critTypes = implode(',', array_map(static fn ($t) => "'" . $t . "'", self::CRITICAL_ALERT_TYPES));

        // ── Query 1: agregado com ROLLUP + MIN/MAX para oldest + last critical ──
        $rows = $this->connection->executeQuery(
            "SELECT
                type,
                COUNT(*) AS total,
                SUM(CASE WHEN collected_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR THEN 1 ELSE 0 END) AS last1h,
                SUM(CASE WHEN collected_at >= UTC_TIMESTAMP() - INTERVAL 2 HOUR THEN 1 ELSE 0 END) AS last2h,
                MIN(collected_at) AS oldest_at,
                MAX(CASE WHEN type IN ({$critTypes}) THEN collected_at END) AS last_critical_at
             FROM waze_alerts
             WHERE is_active = 1 {$pf}
             GROUP BY type WITH ROLLUP",
            $params
        )->fetchAllAssociative();

        $byType = [];
        foreach (self::CRITICAL_ALERT_TYPES as $t) $byType[$t] = 0;

        $total = 0; $last1hTotal = 0; $last2hTotal = 0;
        $crit1h = 0; $crit2h = 0;
        $oldestAt = null;
        $lastCriticalAt = null;

        foreach ($rows as $r) {
            if ($r['type'] === null) {          // linha do ROLLUP = agregado global
                $total          = (int) $r['total'];
                $last1hTotal    = (int) $r['last1h'];
                $last2hTotal    = (int) $r['last2h'];
                $oldestAt       = $r['oldest_at'];
                $lastCriticalAt = $r['last_critical_at'];
                continue;
            }
            $type = (string) $r['type'];
            if (array_key_exists($type, $byType)) {
                $byType[$type] = (int) $r['total'];
                $crit1h += (int) $r['last1h'];
                $crit2h += (int) $r['last2h'];
            }
        }

        // ── Query 2: recentes críticos ──
        $recent = $this->connection->executeQuery(
            "SELECT id, type, subtype, city, street, confidence,
                    CAST(latitude  AS DECIMAL(10,7)) AS lat,
                    CAST(longitude AS DECIMAL(10,7)) AS lng,
                    pub_millis, collected_at
             FROM waze_alerts
             WHERE is_active = 1
               AND type IN ({$critTypes})
               {$pf}
             ORDER BY collected_at DESC, id DESC
             LIMIT 5",
            $params
        )->fetchAllAssociative();

        $recentOut = array_map(fn ($r) => [
            'id'        => (int) $r['id'],
            'kind'      => 'alert',
            'type'      => $r['type'],
            'typeLabel' => $this->labelAlertType((string) $r['type']),
            'subtype'   => $r['subtype'],
            'city'      => $r['city'],
            'street'    => $r['street'],
            'lat'       => (float) $r['lat'],
            'lng'       => (float) $r['lng'],
            'when'      => $this->toIso($r['collected_at']),
        ], $recent);

        // ── Query 3: byHour + topStreets + topCities (UNION ALL) ──
        $extraParams = [];
        $pfPos = '';
        if ($partner !== null) {
            $pfPos = ' AND partner_id = ?';
            $pid = $partner->getId();
            $extraParams = [$pid, $pid, $pid];
        }

        $extraRows = $this->connection->executeQuery(
            "(SELECT 'by_hour' AS kind,
                     DATE_FORMAT(collected_at, '%Y-%m-%d %H:00:00') AS label,
                     COUNT(*) AS cnt,
                     NULL AS extra_a
              FROM waze_alerts
              WHERE is_active = 1
                AND collected_at >= UTC_TIMESTAMP() - INTERVAL 6 HOUR
                {$pfPos}
              GROUP BY label)
             UNION ALL
             (SELECT 'top_street' AS kind,
                     street AS label,
                     COUNT(*) AS cnt,
                     city AS extra_a
              FROM waze_alerts
              WHERE is_active = 1
                AND collected_at >= UTC_TIMESTAMP() - INTERVAL 2 HOUR
                AND street IS NOT NULL AND street <> ''
                {$pfPos}
              GROUP BY street, city
              ORDER BY COUNT(*) DESC
              LIMIT 5)
             UNION ALL
             (SELECT 'top_city' AS kind,
                     city AS label,
                     COUNT(*) AS cnt,
                     NULL AS extra_a
              FROM waze_alerts
              WHERE is_active = 1
                AND collected_at >= UTC_TIMESTAMP() - INTERVAL 2 HOUR
                AND city IS NOT NULL AND city <> ''
                {$pfPos}
              GROUP BY city
              ORDER BY COUNT(*) DESC
              LIMIT 5)",
            $extraParams
        )->fetchAllAssociative();

        $byHourRaw = [];
        $topStreets = [];
        $topCities = [];
        foreach ($extraRows as $r) {
            switch ($r['kind']) {
                case 'by_hour':
                    $byHourRaw[(string) $r['label']] = (int) $r['cnt'];
                    break;
                case 'top_street':
                    $topStreets[] = [
                        'street' => (string) $r['label'],
                        'city'   => (string) ($r['extra_a'] ?? ''),
                        'count'  => (int) $r['cnt'],
                    ];
                    break;
                case 'top_city':
                    $topCities[] = [
                        'city'  => (string) $r['label'],
                        'count' => (int) $r['cnt'],
                    ];
                    break;
            }
        }

        // Preenche 6 buckets de hora (UTC), zerando os ausentes
        $byHour = [];
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTime((int) date('H'), 0, 0);
        for ($i = 5; $i >= 0; $i--) {
            $moment = $nowUtc->modify("-{$i} hour");
            $key    = $moment->format('Y-m-d H:00:00');
            $byHour[] = [
                'at'    => $moment->format('Y-m-d\TH:i:s\Z'),
                'count' => $byHourRaw[$key] ?? 0,
            ];
        }

        return [
            'total'          => $total,
            'last1h'         => ['total' => $last1hTotal, 'critical' => $crit1h],
            'last2h'         => ['total' => $last2hTotal, 'critical' => $crit2h],
            'byType'         => $byType,
            'recent'         => $recentOut,
            'oldestAt'       => $this->toIso($oldestAt),
            'lastCriticalAt' => $this->toIso($lastCriticalAt),
            'byHour'         => $byHour,
            'topStreets'     => $topStreets,
            'topCities'      => $topCities,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadJams(?Partner $partner): array
    {
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND partner_id = ?';
            $params = [$partner->getId(), $partner->getId()];
        }

        $rows = $this->connection->executeQuery(
            "(SELECT
                'totals' AS kind_row,
                COUNT(*)              AS total,
                SUM(level >= 3)       AS level3plus,
                SUM(level >= 4)       AS level4plus,
                AVG(delay)            AS avg_delay,
                NULL AS id, NULL AS street, NULL AS city,
                NULL AS level, NULL AS delay, NULL AS length,
                NULL AS speed_kmh, NULL AS collected_at
             FROM waze_jams
             WHERE is_active = 1 {$pf})
            UNION ALL
            (SELECT
                'top' AS kind_row,
                NULL, NULL, NULL, NULL,
                id, street, city, level, delay, length, speed_kmh, collected_at
             FROM waze_jams
             WHERE is_active = 1 AND level >= 3 {$pf}
             ORDER BY level DESC, delay DESC, collected_at DESC
             LIMIT 5)",
            $params
        )->fetchAllAssociative();

        $totalsRow = null;
        $topRows   = [];
        foreach ($rows as $r) {
            if ($r['kind_row'] === 'totals') $totalsRow = $r;
            else                             $topRows[] = $r;
        }
        $totalsRow ??= ['total' => 0, 'level3plus' => 0, 'level4plus' => 0, 'avg_delay' => null];

        $topOut = array_map(fn ($r) => [
            'id'        => (int) $r['id'],
            'kind'      => 'jam',
            'type'      => 'JAM',
            'typeLabel' => 'Congestionamento',
            'level'     => (int) $r['level'],
            'street'    => $r['street'],
            'city'      => $r['city'],
            'delay'     => (int) $r['delay'],
            'length'    => (int) $r['length'],
            'speed'     => $r['speed_kmh'] !== null ? (float) $r['speed_kmh'] : null,
            'when'      => $this->toIso($r['collected_at']),
        ], $topRows);

        return [
            'total'      => (int) $totalsRow['total'],
            'level3plus' => (int) $totalsRow['level3plus'],
            'level4plus' => (int) $totalsRow['level4plus'],
            'avgDelay'   => $totalsRow['avg_delay'] !== null ? (int) round((float) $totalsRow['avg_delay']) : 0,
            'recent'     => $topOut,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function buildFeed(array $alerts, array $jams): array
    {
        $merged = array_merge($alerts, $jams);

        usort($merged, static function ($a, $b) {
            return strcmp((string) ($b['when'] ?? ''), (string) ($a['when'] ?? ''));
        });

        return array_slice($merged, 0, 6);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadMap(?Partner $partner): array
    {
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        // CASE montado a partir da constante — ordem de severidade
        $caseParts = [];
        foreach (self::MAP_TYPE_PRIORITY as $type => $rank) {
            $caseParts[] = "WHEN '{$type}' THEN {$rank}";
        }
        $caseSql = 'CASE type ' . implode(' ', $caseParts) . ' ELSE 99 END';

        $alerts = $this->connection->executeQuery(
            "SELECT id, type, subtype, street, city,
                    CAST(latitude  AS DECIMAL(10,7)) AS lat,
                    CAST(longitude AS DECIMAL(10,7)) AS lng,
                    collected_at
             FROM waze_alerts
             WHERE is_active = 1
               AND collected_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . self::ALERTS_MAP_WINDOW_HOURS . " HOUR)
               AND latitude IS NOT NULL
               AND longitude IS NOT NULL
               {$pf}
             ORDER BY
                {$caseSql} ASC,
                collected_at DESC
             LIMIT 300",
            $params
        )->fetchAllAssociative();

        $jams = $this->connection->executeQuery(
            "SELECT id, level, street, city, line, delay, length
             FROM waze_jams
             WHERE is_active = 1
               AND level >= " . self::JAMS_MAP_MIN_LEVEL . "
               AND line IS NOT NULL
               {$pf}
             ORDER BY level DESC, delay DESC
             LIMIT 100",
            $params
        )->fetchAllAssociative();

        $jamsOut = [];
        foreach ($jams as $j) {
            $line = $j['line'];
            if (is_string($line)) {
                $decoded = json_decode($line, true);
                $line = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($line) || $line === []) continue;

            $normalized = [];
            foreach ($line as $pt) {
                if (is_array($pt) && isset($pt['x'], $pt['y'])) {
                    $normalized[] = [(float) $pt['y'], (float) $pt['x']];
                } elseif (is_array($pt) && count($pt) >= 2) {
                    $normalized[] = [(float) $pt[1], (float) $pt[0]];
                }
            }
            if ($normalized === []) continue;

            $jamsOut[] = [
                'id'     => (int) $j['id'],
                'level'  => (int) $j['level'],
                'street' => $j['street'],
                'city'   => $j['city'],
                'delay'  => (int) $j['delay'],
                'length' => (int) $j['length'],
                'path'   => $normalized,
            ];
        }

        $alertsOut = array_map(static fn ($a) => [
            'id'      => (int) $a['id'],
            'type'    => $a['type'],
            'subtype' => $a['subtype'],
            'street'  => $a['street'],
            'city'    => $a['city'],
            'lat'     => (float) $a['lat'],
            'lng'     => (float) $a['lng'],
        ], $alerts);

        $center = $this->computeHotZone($alertsOut);

        return [
            'center' => $center,
            'alerts' => $alertsOut,
            'jams'   => $jamsOut,
        ];
    }

    private function computeHotZone(array $alerts): array
    {
        if ($alerts === []) {
            return ['lat' => -20.6607, 'lng' => -43.7856, 'zoom' => 12, 'hasData' => false];
        }

        $slice = array_slice($alerts, 0, 30);
        $n = count($slice);
        $sumLat = 0.0; $sumLng = 0.0;
        foreach ($slice as $a) {
            $sumLat += $a['lat'];
            $sumLng += $a['lng'];
        }

        $zoom = 12;
        if ($n >= 20) $zoom = 13;
        if ($n >= 40) $zoom = 14;

        return [
            'lat'     => $sumLat / $n,
            'lng'     => $sumLng / $n,
            'zoom'    => $zoom,
            'hasData' => true,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadHydro(?Partner $partner): array
    {
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND h.partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $rows = $this->connection->executeQuery(
            "SELECT
                h.id, h.station_name, h.city, h.state,
                o.water_level, o.cota_atencao, o.cota_alerta, o.cota_transbordamento,
                o.observed_at
             FROM cemaden_hidro_station_link h
             LEFT JOIN (
                SELECT
                    o2.cemaden_hidro_station_link_id,
                    o2.water_level,
                    o2.cota_atencao,
                    o2.cota_alerta,
                    o2.cota_transbordamento,
                    o2.observed_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY o2.cemaden_hidro_station_link_id
                        ORDER BY o2.observed_at DESC, o2.id DESC
                    ) AS rn
                FROM cemaden_hidro_observation o2
                WHERE o2.observation_type = 'level'
             ) o
                ON o.cemaden_hidro_station_link_id = h.id
               AND o.rn = 1
             WHERE h.active = 1 {$pf}",
            $params
        )->fetchAllAssociative();

        $stations = [];
        $risk = ['overflow' => 0, 'alert' => 0, 'attention' => 0, 'normal' => 0, 'unknown' => 0];

        foreach ($rows as $r) {
            $level  = $r['water_level']          !== null ? (float) $r['water_level']          : null;
            $attn   = $r['cota_atencao']         !== null ? (float) $r['cota_atencao']         : null;
            $alerta = $r['cota_alerta']          !== null ? (float) $r['cota_alerta']          : null;
            $trans  = $r['cota_transbordamento'] !== null ? (float) $r['cota_transbordamento'] : null;

            $riskLevel = 'unknown';
            if ($level !== null) {
                if ($trans !== null && $level >= $trans) $riskLevel = 'overflow';
                elseif ($alerta !== null && $level >= $alerta) $riskLevel = 'alert';
                elseif ($attn !== null && $level >= $attn) $riskLevel = 'attention';
                else $riskLevel = 'normal';
            }
            $risk[$riskLevel]++;

            $progress = null;
            if ($level !== null && $trans !== null && $trans > 0) {
                $progress = max(0, min(100, ($level / $trans) * 100));
            }

            $stations[] = [
                'id'         => (int) $r['id'],
                'name'       => $r['station_name'] ?: 'Estação',
                'city'       => $r['city'],
                'state'      => $r['state'],
                'level'      => $level,
                'atencao'    => $attn,
                'alerta'     => $alerta,
                'transbordo' => $trans,
                'risk'       => $riskLevel,
                'progress'   => $progress,
                'observedAt' => $this->toIso($r['observed_at']),
            ];
        }

        $order = ['overflow' => 0, 'alert' => 1, 'attention' => 2, 'normal' => 3, 'unknown' => 4];
        usort($stations, static fn ($a, $b) => $order[$a['risk']] <=> $order[$b['risk']]);

        return [
            'stations' => $stations,
            'risk'     => $risk,
            'atRisk'   => $risk['overflow'] + $risk['alert'] + $risk['attention'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Rotas TVT mais lentas vs. histórico de 30 dias.
     *
     * Cada snapshot guarda `time` (segundos no momento) e `historic_time`
     * (segundos esperados — referência histórica). A comparação é:
     *
     *     slowness% = (time - historic_time) / historic_time * 100
     *
     *   positivo → mais lento que o histórico
     *   negativo → mais rápido
     *
     * Se nenhuma rota passar do threshold (10%), cai num fallback
     * mostrando as 3 com maior tempo absoluto, para não deixar o card vazio.
     *
     * ⚠️ Aliases SQL: `current_time` é função built-in no MariaDB e não
     * pode ser usado como alias sem backticks. Usamos `cur_seconds` etc.
     */
    private function loadRoutes(?Partner $partner): array
    {
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND r.partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $curWindow = self::ROUTES_CURRENT_WINDOW_HOURS;
        $histDays  = self::ROUTES_HISTORY_DAYS;

        $rows = $this->connection->executeQuery(
            "SELECT
                r.id,
                r.name,
                r.from_name,
                r.to_name,
                cur.time            AS cur_seconds,
                cur.historic_time   AS cur_historic_seconds,
                cur.jam_level       AS cur_jam_level,
                cur.city            AS cur_city,
                cur.recorded_at     AS cur_recorded_at,
                hist.avg_time       AS hist_avg_seconds,
                hist.avg_historic   AS hist_avg_historic,
                hist.samples        AS hist_samples
             FROM waze_tvt_route r
             INNER JOIN (
                 SELECT s.route_id, s.time, s.historic_time, s.jam_level,
                        s.city, s.recorded_at,
                        ROW_NUMBER() OVER (
                            PARTITION BY s.route_id
                            ORDER BY s.recorded_at DESC, s.id DESC
                        ) AS rn
                 FROM waze_tvt_route_snapshot s
                 WHERE s.recorded_at >= UTC_TIMESTAMP() - INTERVAL {$curWindow} HOUR
             ) cur ON cur.route_id = r.id AND cur.rn = 1
             LEFT JOIN (
                 SELECT route_id,
                        AVG(time)          AS avg_time,
                        AVG(historic_time) AS avg_historic,
                        COUNT(*)           AS samples
                 FROM waze_tvt_route_snapshot
                 WHERE recorded_at >= UTC_TIMESTAMP() - INTERVAL {$histDays} DAY
                   AND time IS NOT NULL
                   AND historic_time IS NOT NULL
                 GROUP BY route_id
             ) hist ON hist.route_id = r.id
             WHERE r.is_active = 1 {$pf}
             LIMIT 200",
            $params
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $curTime   = $r['cur_seconds']          !== null ? (int) $r['cur_seconds']          : null;
            $curHist   = $r['cur_historic_seconds'] !== null ? (int) $r['cur_historic_seconds'] : null;
            $histAvgT  = $r['hist_avg_seconds']     !== null ? (float) $r['hist_avg_seconds']   : null;
            $histAvgH  = $r['hist_avg_historic']    !== null ? (float) $r['hist_avg_historic']  : null;
            $samples   = (int) ($r['hist_samples'] ?? 0);
            $jamLevel  = $r['cur_jam_level']        !== null ? (int) $r['cur_jam_level']        : null;

            // Referência histórica: prioriza a média de 30 dias; se não houver,
            // usa o historic_time do próprio snapshot mais recente.
            $refHistoric = $histAvgH ?? ($curHist !== null ? (float) $curHist : null);
            $refCurrent  = $histAvgT ?? ($curTime !== null ? (float) $curTime : null);

            $slownessPct = null;
            if ($refCurrent !== null && $refHistoric !== null && $refHistoric > 0) {
                $slownessPct = round((($refCurrent - $refHistoric) / $refHistoric) * 100, 1);
            }

            $out[] = [
                'id'            => (int) $r['id'],
                'name'          => $r['name'] ?: trim(($r['from_name'] ?? '') . ' → ' . ($r['to_name'] ?? '')) ?: 'Rota #'.$r['id'],
                'from'          => $r['from_name'],
                'to'            => $r['to_name'],
                'city'          => $r['cur_city'],
                'currentTime'   => $curTime,
                'historicTime'  => $curHist,
                'jamLevel'      => $jamLevel,
                'slownessPct'   => $slownessPct,
                'samples'       => $samples,
                'observedAt'    => $this->toIso($r['cur_recorded_at']),
            ];
        }

        // Ordena por slowness DESC (mais lento primeiro)
        usort($out, static function ($a, $b) {
            $sa = $a['slownessPct'] ?? -9999;
            $sb = $b['slownessPct'] ?? -9999;
            return $sb <=> $sa;
        });

        // Relevantes: passaram do threshold
        $threshold = self::ROUTES_SLOWNESS_THRESHOLD;
        $relevant = array_values(array_filter(
            $out,
            static fn ($r) => ($r['slownessPct'] ?? -9999) >= $threshold
        ));

        $isFallback = false;

        // Fallback: sem nada relevante → top 3 por tempo absoluto atual
        if ($relevant === []) {
            $isFallback = true;
            $byTime = $out;
            usort($byTime, static fn ($a, $b) => ($b['currentTime'] ?? 0) <=> ($a['currentTime'] ?? 0));
            $relevant = array_slice($byTime, 0, 3);
        } else {
            $relevant = array_slice($relevant, 0, self::ROUTES_LIMIT);
        }

        return [
            'items'         => $relevant,
            'total'         => count($out),
            'basedOnDays'   => self::ROUTES_HISTORY_DAYS,
            'threshold'     => $threshold,
            'isFallback'    => $isFallback,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Chuva agora representada pela MÉDIA das estações (não soma).
     * Somar 20 pluviômetros de 2mm virava "40mm" — engana o operador.
     * Média regional + MAX como pico é o que faz sentido no card.
     */
    private function loadRain(?Partner $partner): array
    {
        $pfP = $pfH = '';
        $params = [];
        if ($partner !== null) {
            $pid = $partner->getId();
            $pfP = ' AND s.partner_id = ?';
            $pfH = ' AND h.partner_id = ?';
            $params = [$pid, $pid];
        }

        $row = $this->connection->executeQuery(
            "SELECT
                COALESCE(AVG(station_1h),  0) AS rain_1h,
                COALESCE(AVG(station_24h), 0) AS rain_24h,
                COALESCE(MAX(station_24h), 0) AS peak_24h,
                COUNT(station_24h)            AS station_count
             FROM (
                SELECT
                    SUM(CASE WHEN o.observed_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR
                             THEN o.accumulated_rainfall END) AS station_1h,
                    SUM(CASE WHEN o.observed_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
                             THEN o.accumulated_rainfall END) AS station_24h
                FROM cemaden_station_link s
                LEFT JOIN cemaden_pluviometric_observation o
                    ON o.cemaden_station_link_id = s.id
                   AND o.observed_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
                WHERE s.active = 1 {$pfP}
                GROUP BY s.id

                UNION ALL

                SELECT
                    SUM(CASE WHEN o.observed_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR
                             THEN o.rain END) AS station_1h,
                    SUM(CASE WHEN o.observed_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
                             THEN o.rain END) AS station_24h
                FROM cemaden_hidro_station_link h
                LEFT JOIN cemaden_hidro_observation o
                    ON o.cemaden_hidro_station_link_id = h.id
                   AND o.observation_type = 'rain'
                   AND o.observed_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
                WHERE h.active = 1 {$pfH}
                GROUP BY h.id
             ) per_station",
            $params
        )->fetchAssociative() ?: [];

        // Estação com maior acumulado em 24h (precisa do nome)
        $topParams = [];
        $topWhere  = ['s.active = 1'];
        if ($partner !== null) {
            $topWhere[]       = 's.partner_id = :pid';
            $topParams['pid'] = $partner->getId();
        }
        $wt = implode(' AND ', $topWhere);

        $topStation = $this->connection->executeQuery(
            "SELECT s.station_name, s.city, s.state,
                    COALESCE(SUM(o.accumulated_rainfall), 0) AS rain_24h
             FROM cemaden_station_link s
             INNER JOIN cemaden_pluviometric_observation o
                ON o.cemaden_station_link_id = s.id
               AND o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
             WHERE {$wt}
             GROUP BY s.id, s.station_name, s.city, s.state
             ORDER BY rain_24h DESC
             LIMIT 1",
            $topParams
        )->fetchAssociative();

        return [
            'lastHour'   => round((float) ($row['rain_1h']  ?? 0), 1),
            'last24h'    => round((float) ($row['rain_24h'] ?? 0), 1),
            'peak24h'    => round((float) ($row['peak_24h'] ?? 0), 1),
            'stations'   => (int)   ($row['station_count']  ?? 0),
            'topStation' => $topStation ? [
                'name'    => $topStation['station_name'] ?: 'Estação',
                'city'    => $topStation['city'],
                'state'   => $topStation['state'],
                'rain24h' => round((float) $topStation['rain_24h'], 1),
            ] : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadWeather(?Partner $partner): ?array
    {
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND w.partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $row = $this->connection->executeQuery(
            "SELECT
                w.temperature, w.apparent_temperature, w.relative_humidity,
                w.precipitation, w.rain, w.wind_speed, w.wind_gusts,
                w.weather_code, w.observed_at,
                l.name AS station_name, l.city, l.state
             FROM weather_observation w
             LEFT JOIN weather_location l ON l.id = w.weather_location_id
             WHERE 1=1 {$pf}
             ORDER BY w.observed_at DESC, w.id DESC
             LIMIT 1",
            $params
        )->fetchAssociative();

        if (!$row) return null;

        return [
            'temperature'   => $row['temperature'] !== null ? (float) $row['temperature'] : null,
            'apparentTemp'  => $row['apparent_temperature'] !== null ? (float) $row['apparent_temperature'] : null,
            'humidity'      => $row['relative_humidity'] !== null ? (int) $row['relative_humidity'] : null,
            'precipitation' => $row['precipitation'] !== null ? (float) $row['precipitation'] : null,
            'rain'          => $row['rain'] !== null ? (float) $row['rain'] : null,
            'windSpeed'     => $row['wind_speed'] !== null ? (float) $row['wind_speed'] : null,
            'windGusts'     => $row['wind_gusts'] !== null ? (float) $row['wind_gusts'] : null,
            'weatherCode'   => $row['weather_code'] !== null ? (int) $row['weather_code'] : null,
            'stationName'   => $row['station_name'],
            'city'          => $row['city'],
            'state'         => $row['state'],
            'observedAt'    => $this->toIso($row['observed_at']),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadToday(?Partner $partner): array
    {
        $tz           = new \DateTimeZone($this->appTimezone());
        $utc          = new \DateTimeZone('UTC');
        $localNow     = new \DateTimeImmutable('now', $tz);
        $startOfToday = $localNow->setTime(0, 0, 0);

        $utcStartToday     = $startOfToday->setTimezone($utc)->format('Y-m-d H:i:s');
        $utcStartYesterday = $startOfToday->modify('-1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $utcStartTomorrow  = $startOfToday->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $utcStartLastWeek  = $startOfToday->modify('-7 days')->setTimezone($utc)->format('Y-m-d H:i:s');
        $utcEndLastWeek    = $startOfToday->modify('-6 days')->setTimezone($utc)->format('Y-m-d H:i:s');

        $pfA = $pfJ = '';
        $params = [
            $utcStartToday,
            $utcStartYesterday,
            $utcStartTomorrow,
        ];
        if ($partner !== null) {
            $pfA = ' AND partner_id = ?';
            $pfJ = ' AND partner_id = ?';
            $params[] = $partner->getId();
        }
        $params[] = $utcStartToday;
        $params[] = $utcStartYesterday;
        $params[] = $utcStartTomorrow;
        if ($partner !== null) {
            $params[] = $partner->getId();
        }

        $row = $this->connection->executeQuery(
            "SELECT
                SUM(src='alert' AND bucket='today')                                 AS alerts_today,
                SUM(src='alert' AND bucket='today' AND type='ACCIDENT')             AS accidents_today,
                SUM(src='alert' AND bucket='today' AND type='ROAD_CLOSED')          AS road_closed_today,
                SUM(src='alert' AND bucket='today' AND type='HAZARD_WEATHER_FLOOD') AS floods_today,
                SUM(src='alert' AND bucket='yesterday')                             AS alerts_yesterday,
                SUM(src='jam'   AND bucket='today')                                 AS jams_today
             FROM (
                SELECT 'alert' AS src, type,
                       CASE WHEN collected_at >= ? THEN 'today' ELSE 'yesterday' END AS bucket
                FROM waze_alerts
                WHERE collected_at >= ? AND collected_at < ?
                  {$pfA}
                UNION ALL
                SELECT 'jam', NULL,
                       CASE WHEN collected_at >= ? THEN 'today' ELSE 'yesterday' END
                FROM waze_jams
                WHERE collected_at >= ? AND collected_at < ?
                  {$pfJ}
             ) t",
            $params
        )->fetchAssociative() ?: [];

        $paramsLW = [$utcStartLastWeek, $utcEndLastWeek];
        $pfLW = '';
        if ($partner !== null) {
            $pfLW = ' AND partner_id = ?';
            $paramsLW[] = $partner->getId();
        }
        $lastWeekAlerts = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM waze_alerts
             WHERE collected_at >= ? AND collected_at < ?
               {$pfLW}",
            $paramsLW
        )->fetchOne();

        $today     = (int) ($row['alerts_today'] ?? 0);
        $yesterday = (int) ($row['alerts_yesterday'] ?? 0);

        return [
            'alerts'              => $today,
            'accidents'           => (int) ($row['accidents_today'] ?? 0),
            'roadClosed'          => (int) ($row['road_closed_today'] ?? 0),
            'floods'              => (int) ($row['floods_today'] ?? 0),
            'jams'                => (int) ($row['jams_today'] ?? 0),
            'trend'               => $yesterday > 0
                ? round((($today - $yesterday) / $yesterday) * 100, 1)
                : null,
            'sameWeekday'         => $lastWeekAlerts,
            'trendVsLastWeek'     => $lastWeekAlerts > 0
                ? round((($today - $lastWeekAlerts) / $lastWeekAlerts) * 100, 1)
                : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadFetchStatus(?Partner $partner): array
    {
        $pfAlerts = $pfTvt = $pfW = $pfH = $pfP = '';
        $params = [];
        if ($partner !== null) {
            $pid = $partner->getId();
            $pfAlerts = ' AND id = ?';
            $pfTvt    = ' AND id = ?';
            $pfW      = ' AND partner_id = ?';
            $pfH      = ' AND partner_id = ?';
            $pfP      = ' AND partner_id = ?';
            $params   = [$pid, $pid, $pid, $pid, $pid];
        }

        $row = $this->connection->executeQuery(
            "SELECT
                (SELECT MAX(last_fetch_at)     FROM partner WHERE 1=1 {$pfAlerts})                     AS alerts_fetch,
                (SELECT MAX(last_tvt_fetch_at) FROM partner WHERE 1=1 {$pfTvt})                        AS tvt_fetch,
                (SELECT MAX(observed_at)       FROM weather_observation WHERE 1=1 {$pfW})              AS weather_fetch,
                (SELECT MAX(last_fetched_at)   FROM cemaden_hidro_station_link  WHERE active=1 {$pfH}) AS hydro_fetch,
                (SELECT MAX(last_fetched_at)   FROM cemaden_station_link        WHERE active=1 {$pfP}) AS pluvio_fetch",
            $params
        )->fetchAssociative() ?: [];

        return [
            'alerts'  => $this->toIso($row['alerts_fetch']  ?? null),
            'jams'    => $this->toIso($row['alerts_fetch']  ?? null),
            'tvt'     => $this->toIso($row['tvt_fetch']     ?? null),
            'weather' => $this->toIso($row['weather_fetch'] ?? null),
            'hydro'   => $this->toIso($row['hydro_fetch']   ?? null),
            'pluvio'  => $this->toIso($row['pluvio_fetch']  ?? null),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadCameras(?Partner $partner): array
    {
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $rows = $this->connection->executeQuery(
            "SELECT id, name, city, state, url, url_type
             FROM partner_camera_link
             WHERE is_active = 1
               AND url IS NOT NULL
               AND url <> ''
               {$pf}
             ORDER BY id ASC
             LIMIT 30",
            $params
        )->fetchAllAssociative();

        return array_map(static function ($r) {
            $rawUrl = (string) $r['url'];
            $path   = parse_url($rawUrl, PHP_URL_PATH);
            $path   = is_string($path) ? ltrim($path, '/') : '';

            return [
                'id'      => (int) $r['id'],
                'name'    => $r['name'] ?: 'Câmera #'.$r['id'],
                'city'    => $r['city'],
                'state'   => $r['state'],
                'url'     => '/tv/camera/' . (int) $r['id'] . '/hls?file=' . rawurlencode($path),
                'urlType' => 'hls',
            ];
        }, $rows);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->format(DATE_ATOM);
        }
        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function labelAlertType(string $type): string
    {
        return match (strtoupper($type)) {
            'HAZARD'               => 'Perigo',
            'ROAD_CLOSED'          => 'Via fechada',
            'ACCIDENT'             => 'Acidente',
            'JAM'                  => 'Congestionamento',
            'POLICE'               => 'Polícia',
            'WEATHERHAZARD'        => 'Perigo climático',
            'HAZARD_WEATHER_FLOOD' => 'Alagamento',
            'CONSTRUCTION'         => 'Obras',
            default                => ucfirst(strtolower($type)) ?: 'Alerta',
        };
    }
}
