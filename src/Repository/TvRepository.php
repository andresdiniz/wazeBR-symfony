<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;

/**
 * Agregação para a tela de TV / wallboard (/tv).
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

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string,mixed> */
    public function getWallboard(?Partner $partner): array
    {
        $alerts   = $this->loadAlerts($partner);
        $jams     = $this->loadJams($partner);
        $feed     = $this->buildFeed($alerts['recent'], $jams['recent']);
        $map      = $this->loadMap($partner);
        $hydro    = $this->loadHydro($partner);
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

        $totals = $this->connection->executeQuery(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN collected_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS last1h,
                SUM(CASE WHEN collected_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) THEN 1 ELSE 0 END) AS last2h
             FROM waze_alerts
             WHERE is_active = 1 {$pf}",
            $params
        )->fetchAssociative() ?: [];

        $byTypeRows = $this->connection->executeQuery(
            "SELECT type, COUNT(*) AS total
             FROM waze_alerts
             WHERE is_active = 1 {$pf}
             GROUP BY type",
            $params
        )->fetchAllAssociative();

        $byType = [];
        foreach (self::CRITICAL_ALERT_TYPES as $t) $byType[$t] = 0;
        foreach ($byTypeRows as $r) {
            $t = (string) $r['type'];
            if (array_key_exists($t, $byType)) {
                $byType[$t] = (int) $r['total'];
            }
        }

        $critTypes = implode(',', array_map(static fn ($t) => "'" . $t . "'", self::CRITICAL_ALERT_TYPES));

        $critLast1h = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM waze_alerts
             WHERE is_active = 1
               AND collected_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
               AND type IN ({$critTypes})
               {$pf}",
            $params
        )->fetchOne();

        $critLast2h = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM waze_alerts
             WHERE is_active = 1
               AND collected_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)
               AND type IN ({$critTypes})
               {$pf}",
            $params
        )->fetchOne();

        $recent = $this->connection->executeQuery(
            "SELECT id, type, subtype, city, street, confidence,
                    CAST(latitude AS DECIMAL(10,7)) AS lat,
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
            'id'         => (int) $r['id'],
            'kind'       => 'alert',
            'type'       => $r['type'],
            'typeLabel'  => $this->labelAlertType((string) $r['type']),
            'subtype'    => $r['subtype'],
            'city'       => $r['city'],
            'street'     => $r['street'],
            'lat'        => (float) $r['lat'],
            'lng'        => (float) $r['lng'],
            'when'       => $this->toIso($r['collected_at']),   // ← fix timezone
        ], $recent);

        return [
            'total'   => (int) ($totals['total']  ?? 0),
            'last1h'  => [
                'total'    => (int) ($totals['last1h'] ?? 0),
                'critical' => $critLast1h,
            ],
            'last2h'  => [
                'total'    => (int) ($totals['last2h'] ?? 0),
                'critical' => $critLast2h,
            ],
            'byType'  => $byType,
            'recent'  => $recentOut,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadJams(?Partner $partner): array
    {
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $totals = $this->connection->executeQuery(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN level >= 3 THEN 1 ELSE 0 END) AS level3plus,
                SUM(CASE WHEN level >= 4 THEN 1 ELSE 0 END) AS level4plus,
                AVG(delay) AS avg_delay
             FROM waze_jams
             WHERE is_active = 1 {$pf}",
            $params
        )->fetchAssociative() ?: [];

        $top = $this->connection->executeQuery(
            "SELECT id, street, city, level, delay, length, speed_kmh, collected_at
             FROM waze_jams
             WHERE is_active = 1
               AND level >= 3
               {$pf}
             ORDER BY level DESC, delay DESC, collected_at DESC
             LIMIT 5",
            $params
        )->fetchAllAssociative();

        $topOut = array_map(fn ($r) => [
            'id'         => (int) $r['id'],
            'kind'       => 'jam',
            'type'       => 'JAM',
            'typeLabel'  => 'Congestionamento',
            'level'      => (int) $r['level'],
            'street'     => $r['street'],
            'city'       => $r['city'],
            'delay'      => (int) $r['delay'],
            'length'     => (int) $r['length'],
            'speed'      => $r['speed_kmh'] !== null ? (float) $r['speed_kmh'] : null,
            'when'       => $this->toIso($r['collected_at']),   // ← fix timezone
        ], $top);

        return [
            'total'      => (int) ($totals['total']      ?? 0),
            'level3plus' => (int) ($totals['level3plus'] ?? 0),
            'level4plus' => (int) ($totals['level4plus'] ?? 0),
            'avgDelay'   => $totals['avg_delay'] !== null ? (int) round((float) $totals['avg_delay']) : 0,
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
             ORDER BY collected_at DESC
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
            'id'     => (int) $a['id'],
            'type'   => $a['type'],
            'subtype'=> $a['subtype'],
            'street' => $a['street'],
            'city'   => $a['city'],
            'lat'    => (float) $a['lat'],
            'lng'    => (float) $a['lng'],
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
             LEFT JOIN cemaden_hidro_observation o
                ON o.cemaden_hidro_station_link_id = h.id
                AND o.observation_type = 'level'
                AND o.id = (
                    SELECT o2.id FROM cemaden_hidro_observation o2
                    WHERE o2.cemaden_hidro_station_link_id = h.id
                      AND o2.observation_type = 'level'
                    ORDER BY o2.observed_at DESC, o2.id DESC
                    LIMIT 1
                )
             WHERE h.active = 1 {$pf}",
            $params
        )->fetchAllAssociative();

        $stations = [];
        $risk = ['overflow' => 0, 'alert' => 0, 'attention' => 0, 'normal' => 0, 'unknown' => 0];

        foreach ($rows as $r) {
            $level = $r['water_level']          !== null ? (float) $r['water_level']          : null;
            $attn  = $r['cota_atencao']         !== null ? (float) $r['cota_atencao']         : null;
            $alerta= $r['cota_alerta']          !== null ? (float) $r['cota_alerta']          : null;
            $trans = $r['cota_transbordamento'] !== null ? (float) $r['cota_transbordamento'] : null;

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
                'id'        => (int) $r['id'],
                'name'      => $r['station_name'] ?: 'Estação',
                'city'      => $r['city'],
                'state'     => $r['state'],
                'level'     => $level,
                'atencao'   => $attn,
                'alerta'    => $alerta,
                'transbordo'=> $trans,
                'risk'      => $riskLevel,
                'progress'  => $progress,
                'observedAt'=> $this->toIso($r['observed_at']),   // ← fix timezone
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

    private function loadRain(?Partner $partner): array
    {
        $pParams = [];
        $pWhere  = ['s.active = 1'];
        if ($partner !== null) {
            $pWhere[]         = 's.partner_id = :pid';
            $pParams['pid']   = $partner->getId();
        }
        $wp = implode(' AND ', $pWhere);

        $pluvio = $this->connection->executeQuery(
            "SELECT
                COALESCE(SUM(CASE WHEN o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) THEN o.accumulated_rainfall END), 0) AS rain_1h,
                COALESCE(SUM(CASE WHEN o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) THEN o.accumulated_rainfall END), 0) AS rain_24h,
                COALESCE(MAX(CASE WHEN o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) THEN o.accumulated_rainfall END), 0) AS peak_24h
             FROM cemaden_station_link s
             LEFT JOIN cemaden_pluviometric_observation o
                ON o.cemaden_station_link_id = s.id
                AND o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
             WHERE $wp",
            $pParams
        )->fetchAssociative() ?: [];

        $hParams = [];
        $hWhere  = ['h.active = 1'];
        if ($partner !== null) {
            $hWhere[]         = 'h.partner_id = :pid';
            $hParams['pid']   = $partner->getId();
        }
        $wh = implode(' AND ', $hWhere);

        $hydroRain = $this->connection->executeQuery(
            "SELECT
                COALESCE(SUM(CASE WHEN o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) THEN o.rain END), 0) AS rain_1h,
                COALESCE(SUM(CASE WHEN o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) THEN o.rain END), 0) AS rain_24h,
                COALESCE(MAX(CASE WHEN o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) THEN o.rain END), 0) AS peak_24h
             FROM cemaden_hidro_station_link h
             LEFT JOIN cemaden_hidro_observation o
                ON o.cemaden_hidro_station_link_id = h.id
                AND o.observation_type = 'rain'
                AND o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
             WHERE $wh",
            $hParams
        )->fetchAssociative() ?: [];

        $rain1h  = (float) ($pluvio['rain_1h']  ?? 0) + (float) ($hydroRain['rain_1h']  ?? 0);
        $rain24h = (float) ($pluvio['rain_24h'] ?? 0) + (float) ($hydroRain['rain_24h'] ?? 0);
        $peak24h = max((float) ($pluvio['peak_24h'] ?? 0), (float) ($hydroRain['peak_24h'] ?? 0));

        $topParams = [];
        $topWhere  = ['s.active = 1'];
        if ($partner !== null) {
            $topWhere[]         = 's.partner_id = :pid';
            $topParams['pid']   = $partner->getId();
        }
        $wt = implode(' AND ', $topWhere);

        $topStation = $this->connection->executeQuery(
            "SELECT s.station_name, s.city, s.state,
                    COALESCE(SUM(o.accumulated_rainfall), 0) AS rain_24h
             FROM cemaden_station_link s
             INNER JOIN cemaden_pluviometric_observation o
                ON o.cemaden_station_link_id = s.id
                AND o.observed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
             WHERE $wt
             GROUP BY s.id, s.station_name, s.city, s.state
             ORDER BY rain_24h DESC
             LIMIT 1",
            $topParams
        )->fetchAssociative();

        return [
            'lastHour'   => round($rain1h, 1),
            'last24h'    => round($rain24h, 1),
            'peak24h'    => round($peak24h, 1),
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
            'observedAt'    => $this->toIso($row['observed_at']),   // ← fix timezone
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadToday(?Partner $partner): array
    {
        $pf = '';
        $params = [];
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $alertsToday = $this->connection->executeQuery(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN type = 'ACCIDENT' THEN 1 ELSE 0 END) AS accidents,
                SUM(CASE WHEN type = 'ROAD_CLOSED' THEN 1 ELSE 0 END) AS road_closed,
                SUM(CASE WHEN type = 'HAZARD_WEATHER_FLOOD' THEN 1 ELSE 0 END) AS floods
             FROM waze_alerts
             WHERE collected_at >= DATE_SUB(DATE(UTC_TIMESTAMP()), INTERVAL 0 DAY) + INTERVAL 3 HOUR
               AND collected_at < DATE(UTC_TIMESTAMP()) + INTERVAL 1 DAY + INTERVAL 3 HOUR
               {$pf}",
            $params
        )->fetchAssociative() ?: [];

        $alertsYesterday = $this->connection->executeQuery(
            "SELECT COUNT(*) FROM waze_alerts
             WHERE collected_at >= DATE_SUB(DATE(UTC_TIMESTAMP()), INTERVAL 1 DAY) + INTERVAL 3 HOUR
               AND collected_at < DATE(UTC_TIMESTAMP()) + INTERVAL 3 HOUR
               {$pf}",
            $params
        )->fetchOne();

        $jamsToday = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM waze_jams
             WHERE collected_at >= DATE_SUB(DATE(UTC_TIMESTAMP()), INTERVAL 0 DAY) + INTERVAL 3 HOUR
               AND collected_at < DATE(UTC_TIMESTAMP()) + INTERVAL 1 DAY + INTERVAL 3 HOUR
               {$pf}",
            $params
        )->fetchOne();

        $alertsNow = (int) ($alertsToday['total'] ?? 0);
        $yesterday = (int) $alertsYesterday;

        $trend = null;
        if ($yesterday > 0) {
            $trend = round((($alertsNow - $yesterday) / $yesterday) * 100, 1);
        }

        return [
            'alerts'     => $alertsNow,
            'accidents'  => (int) ($alertsToday['accidents'] ?? 0),
            'roadClosed' => (int) ($alertsToday['road_closed'] ?? 0),
            'floods'     => (int) ($alertsToday['floods'] ?? 0),
            'jams'       => $jamsToday,
            'trend'      => $trend,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadFetchStatus(?Partner $partner): array
    {
        $status = [
            'alerts'  => null,
            'jams'    => null,
            'tvt'     => null,
            'weather' => null,
            'hydro'   => null,
            'pluvio'  => null,
        ];

        if ($partner !== null) {
            $status['alerts'] = $this->toIso($partner->getLastFetchAt());
            $status['jams']   = $this->toIso($partner->getLastFetchAt());
            $status['tvt']    = $this->toIso($partner->getLastTvtFetchAt());
        } else {
            $row = $this->connection->executeQuery(
                "SELECT MAX(last_fetch_at) FROM partner WHERE last_fetch_at IS NOT NULL"
            )->fetchOne();
            $status['alerts'] = $this->toIso($row);
            $status['jams']   = $this->toIso($row);

            $rowTvt = $this->connection->executeQuery(
                "SELECT MAX(last_tvt_fetch_at) FROM partner WHERE last_tvt_fetch_at IS NOT NULL"
            )->fetchOne();
            $status['tvt'] = $this->toIso($rowTvt);
        }

        $params = [];
        $pfW = '';
        $pfH = '';
        $pfP = '';
        if ($partner !== null) {
            $params['pid'] = $partner->getId();
            $pfW = ' WHERE partner_id = :pid';
            $pfH = ' WHERE partner_id = :pid AND active = 1';
            $pfP = ' WHERE partner_id = :pid AND active = 1';
        } else {
            $pfH = ' WHERE active = 1';
            $pfP = ' WHERE active = 1';
        }

        $status['weather'] = $this->toIso($this->connection->executeQuery(
            "SELECT MAX(observed_at) FROM weather_observation $pfW",
            $params
        )->fetchOne());

        $status['hydro'] = $this->toIso($this->connection->executeQuery(
            "SELECT MAX(last_fetched_at) FROM cemaden_hidro_station_link $pfH",
            $params
        )->fetchOne());

        $status['pluvio'] = $this->toIso($this->connection->executeQuery(
            "SELECT MAX(last_fetched_at) FROM cemaden_station_link $pfP",
            $params
        )->fetchOne());

        return $status;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Câmeras ativas do parceiro para o carrossel da TV.
     *
     * ⚠️ Devolve a URL do PROXY (/tv/camera/{id}/hls?file=...), não a
     * URL bruta da câmera. Motivo: as câmeras são HLS sem CORS, então
     * o hls.js não consegue fazer XHR direto. O proxy repassa manifestos
     * e segmentos via same-origin.
     *
     * O `file` já vem com o path relativo correto pra câmera. Ex.:
     * para a URL http://200.144.30.103:8084/hls/cam_23/stream5564.ts
     * o proxy recebe ?file=hls/cam_23/stream5564.ts.
     *
     * @return list<array{id:int,name:string,city:?string,state:?string,url:string,urlType:string}>
     */
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
            'HAZARD'              => 'Perigo',
            'ROAD_CLOSED'         => 'Via fechada',
            'ACCIDENT'            => 'Acidente',
            'JAM'                 => 'Congestionamento',
            'POLICE'              => 'Polícia',
            'WEATHERHAZARD'       => 'Perigo climático',
            'HAZARD_WEATHER_FLOOD'=> 'Alagamento',
            'CONSTRUCTION'        => 'Obras',
            default               => ucfirst(strtolower($type)) ?: 'Alerta',
        };
    }
}
