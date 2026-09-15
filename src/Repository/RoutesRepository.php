<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class RoutesRepository
{
    private const MAX_ROUTES       = 200;
    private const MAX_SUBROUTES    = 40;
    private const MAX_LINE_POINTS  = 80;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{routes:list<array>,stats:array,map:array} */
    public function getRoutesDashboard(?Partner $partner, array $filters = []): array
    {
        $filters = [
            'query' => trim((string) ($filters['query'] ?? '')),
            'level' => in_array($filters['level'] ?? 'all', ['all','none','light','moderate','heavy','severe'], true)
                ? (string) $filters['level']
                : 'all',
        ];

        $routesRaw = $this->loadRoutesWithLatestSnapshot($partner);
        $routeIds  = array_map(static fn ($r) => (int) $r['id'], $routesRaw);

        $subRoutesByRoute = $this->loadSubRoutesGrouped($partner, $routeIds);
        $irregsByRoute    = $this->loadIrregularitiesGrouped($partner, $routeIds);

        $routes   = [];
        $segments = [];
        $irregs   = [];

        foreach ($routesRaw as $row) {
            $id       = (int) $row['id'];
            $current  = $row['current_time_seconds']  !== null ? (float) $row['current_time_seconds']  : null;
            $historic = $row['historic_time_seconds'] !== null ? (float) $row['historic_time_seconds'] : null;

            $delay = ($current !== null && $historic !== null)
                ? max(0, (int) round($current - $historic))
                : null;

            $level = $this->computeDelayLevel($delay, $historic);

            $subRoutes      = $subRoutesByRoute[$id] ?? [];
            $irregularities = $irregsByRoute[$id]    ?? [];

            $routeName = $row['snapshot_name'] ?: ($row['route_name'] ?: 'Rota monitorada');

            $routes[] = [
                'id'               => $id,
                'routeId'          => (string) $row['waze_route_id'],
                'name'             => $routeName,
                'from'             => $row['from_name'],
                'to'               => $row['to_name'],
                'city'             => $row['snapshot_city'] ?: null,
                'state'            => $row['snapshot_state'] ?: null,
                'time'             => $current,
                'historicTime'     => $historic,
                'delaySeconds'     => $delay,
                'delayMinutes'     => $delay !== null ? round($delay / 60, 1) : null,
                'delayRatio'       => $level['ratio'],
                'delayScore'       => $level['score'],
                'delayLevel'       => $level['level'],
                'delayLabel'       => $level['label'],
                'jamLevel'         => $row['jam_level'] !== null ? (int) $row['jam_level'] : null,
                'recordedAt'       => $row['recorded_at'],
                'lastSeenAt'       => $row['route_last_seen_at'],
                'hasGeometry'      => !empty($row['geometry']),
                'hasSubRoutes'     => $subRoutes !== [],
                'irregularityCount'=> count($irregularities),
            ];

            // ── Segmentos para o mapa ──────────────────────────────
            if ($subRoutes !== []) {
                foreach ($subRoutes as $sub) {
                    if (empty($sub['line'])) continue;

                    $subLevel = $this->computeDelayLevel(
                        $sub['delaySeconds'] ?? null,
                        $sub['historicTime'] ?? null,
                        $sub['jamLevel'] ?? null,
                    );

                    $segments[] = [
                        'routeId'      => $id,
                        'routeName'    => $routeName,
                        'path'         => $sub['line'],
                        'delayLevel'   => $subLevel['level'],
                        'delayLabel'   => $subLevel['label'],
                        'delaySeconds' => $sub['delaySeconds'] ?? null,
                        'delayRatio'   => $subLevel['ratio'],
                        'delayScore'   => $subLevel['score'],
                        'jamLevel'     => $sub['jamLevel'] ?? null,
                        'from'         => $sub['fromName'] ?? null,
                        'to'           => $sub['toName'] ?? null,
                        'time'         => $sub['time'] ?? null,
                        'historicTime' => $sub['historicTime'] ?? null,
                        'source'       => 'subroute',

                        // ── Contexto da ROTA completa (pra popup) ──
                        'routeFrom'            => $row['from_name'],
                        'routeTo'              => $row['to_name'],
                        'routeTime'            => $current,
                        'routeHistoricTime'    => $historic,
                        'routeDelaySeconds'    => $delay,
                        'routeDelayRatio'      => $level['ratio'],
                        'routeDelayLevel'      => $level['level'],
                        'routeDelayLabel'      => $level['label'],
                    ];
                }
            } elseif (!empty($row['geometry'])) {
                $line = $this->normalizeLine($row['geometry']);
                if ($line !== []) {
                    $segments[] = [
                        'routeId'      => $id,
                        'routeName'    => $routeName,
                        'path'         => $line,
                        'delayLevel'   => $level['level'],
                        'delayLabel'   => $level['label'],
                        'delaySeconds' => $delay,
                        'delayRatio'   => $level['ratio'],
                        'delayScore'   => $level['score'],
                        'jamLevel'     => $row['jam_level'] !== null ? (int) $row['jam_level'] : null,
                        'from'         => $row['from_name'],
                        'to'           => $row['to_name'],
                        'time'         => $current,
                        'historicTime' => $historic,
                        'source'       => 'route',

                        // Segmento == rota, contexto igual
                        'routeFrom'            => $row['from_name'],
                        'routeTo'              => $row['to_name'],
                        'routeTime'            => $current,
                        'routeHistoricTime'    => $historic,
                        'routeDelaySeconds'    => $delay,
                        'routeDelayRatio'      => $level['ratio'],
                        'routeDelayLevel'      => $level['level'],
                        'routeDelayLabel'      => $level['label'],
                    ];
                }
            }

            foreach ($irregularities as $irr) {
                if ($irr['lat'] === null || $irr['lng'] === null) continue;
                $irregs[] = [
                    'routeId'     => $id,
                    'type'        => $irr['type'],
                    'subtype'     => $irr['subtype'],
                    'severity'    => $irr['severity'],
                    'lat'         => (float) $irr['lat'],
                    'lng'         => (float) $irr['lng'],
                    'street'      => $irr['street'],
                    'city'        => $irr['city'],
                    'description' => $irr['description'],
                ];
            }
        }

        if ($filters['query'] !== '') {
            $needle = mb_strtolower($filters['query']);
            $routes = array_values(array_filter($routes, static function ($r) use ($needle) {
                $haystack = mb_strtolower(sprintf(
                    '%s %s %s %s',
                    $r['name'] ?? '',
                    $r['from'] ?? '',
                    $r['to']   ?? '',
                    $r['city'] ?? '',
                ));
                return str_contains($haystack, $needle);
            }));
        }

        if ($filters['level'] !== 'all') {
            $routes = array_values(array_filter(
                $routes,
                static fn ($r) => $r['delayLevel'] === $filters['level'],
            ));
        }

        usort($routes, static function ($a, $b) {
            $sa = $a['delayScore'] ?? -1.0;
            $sb = $b['delayScore'] ?? -1.0;
            if ($sa !== $sb) return $sb <=> $sa;

            $da = $a['delaySeconds'] ?? -1;
            $db = $b['delaySeconds'] ?? -1;
            if ($da !== $db) return $db <=> $da;

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $visibleRouteIds = array_flip(array_map(static fn ($r) => $r['id'], $routes));
        $segments = array_values(array_filter(
            $segments,
            static fn ($s) => isset($visibleRouteIds[$s['routeId']]),
        ));
        $irregs = array_values(array_filter(
            $irregs,
            static fn ($i) => isset($visibleRouteIds[$i['routeId']]),
        ));

        $order = ['none' => 0, 'light' => 1, 'moderate' => 2, 'heavy' => 3, 'severe' => 4];
        usort($segments, static function ($a, $b) use ($order) {
            return ($order[$a['delayLevel']] ?? 0) <=> ($order[$b['delayLevel']] ?? 0);
        });

        return [
            'routes' => $routes,
            'stats'  => $this->computeStats($routes),
            'map'    => [
                'center'         => $this->computeCenter($segments),
                'segments'       => $segments,
                'irregularities' => $irregs,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Queries
    // ─────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function loadRoutesWithLatestSnapshot(?Partner $partner): array
    {
        $partnerFilter = '';
        $params        = [];
        if ($partner !== null) {
            $partnerFilter        = ' AND r.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $limit = self::MAX_ROUTES;

        $sql = <<<SQL
SELECT
    r.id,
    r.route_id            AS waze_route_id,
    r.name                AS route_name,
    r.from_name,
    r.to_name,
    r.geometry,
    r.last_seen_at        AS route_last_seen_at,
    s.name                AS snapshot_name,
    s.city                AS snapshot_city,
    s.state               AS snapshot_state,
    s.time                AS current_time_seconds,
    s.historic_time       AS historic_time_seconds,
    s.jam_level,
    s.recorded_at
FROM waze_tvt_route r
LEFT JOIN waze_tvt_route_snapshot s
    ON s.id = (
        SELECT latest.id FROM waze_tvt_route_snapshot latest
        WHERE latest.waze_route_id = r.route_id
        ORDER BY latest.recorded_at DESC, latest.id DESC
        LIMIT 1
    )
WHERE r.is_active = 1 {$partnerFilter}
ORDER BY
    CASE
        WHEN s.time IS NOT NULL
         AND s.historic_time IS NOT NULL
         AND s.historic_time > 0
        THEN (s.time - s.historic_time) / s.historic_time
        ELSE -1
    END DESC,
    s.recorded_at DESC
LIMIT {$limit}
SQL;

        return $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /** @param int[] $routeIds @return array<int, list<array<string,mixed>>> */
    private function loadSubRoutesGrouped(?Partner $partner, array $routeIds): array
    {
        if ($routeIds === []) return [];

        $params        = ['ids' => $routeIds];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND sr.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = <<<SQL
SELECT
    sr.id,
    sr.route_id          AS route_fk,
    sr.waze_route_id     AS waze_route_id,
    sr.sub_route_id      AS sub_route_id,
    sr.name,
    sr.from_name,
    sr.to_name,
    sr.line,
    sr.bbox,
    sr.time,
    sr.historic_time,
    sr.jam_level
FROM waze_tvt_sub_route sr
WHERE sr.is_active = 1
  AND sr.route_id IN (:ids)
  {$partnerFilter}
ORDER BY sr.route_id ASC, sr.id ASC
SQL;

        $rows = $this->connection
            ->executeQuery($sql, $params, ['ids' => ArrayParameterType::INTEGER])
            ->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            $routeFk = (int) $row['route_fk'];
            if (count($grouped[$routeFk] ?? []) >= self::MAX_SUBROUTES) continue;

            $line = $this->normalizeLine($row['line']);
            if ($line === []) continue;

            $current  = $row['time']          !== null ? (float) $row['time']          : null;
            $historic = $row['historic_time'] !== null ? (float) $row['historic_time'] : null;

            $delay = ($current !== null && $historic !== null)
                ? max(0, (int) round($current - $historic))
                : null;

            $grouped[$routeFk][] = [
                'id'           => (int) $row['id'],
                'subRouteId'   => $row['sub_route_id'],
                'name'         => $row['name'],
                'fromName'     => $row['from_name'],
                'toName'       => $row['to_name'],
                'line'         => $line,
                'time'         => $current,
                'historicTime' => $historic,
                'delaySeconds' => $delay,
                'jamLevel'     => $row['jam_level'] !== null ? (int) $row['jam_level'] : null,
            ];
        }

        return $grouped;
    }

    /** @param int[] $routeIds @return array<int, list<array<string,mixed>>> */
    private function loadIrregularitiesGrouped(?Partner $partner, array $routeIds): array
    {
        if ($routeIds === []) return [];

        $params        = ['ids' => $routeIds];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = <<<SQL
SELECT
    id, route_id, type, subtype, severity, description,
    street, city, state, latitude, longitude, reported_time
FROM waze_tvt_irregularity
WHERE is_active = 1
  AND route_id IN (:ids)
  {$partnerFilter}
ORDER BY reported_time DESC
LIMIT 500
SQL;

        $rows = $this->connection
            ->executeQuery($sql, $params, ['ids' => ArrayParameterType::INTEGER])
            ->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['route_id']][] = [
                'id'          => (int) $row['id'],
                'type'        => $row['type'],
                'subtype'     => $row['subtype'],
                'severity'    => $row['severity'],
                'description' => $row['description'],
                'street'      => $row['street'],
                'city'        => $row['city'],
                'state'       => $row['state'],
                'lat'         => $row['latitude']  !== null ? (float) $row['latitude']  : null,
                'lng'         => $row['longitude'] !== null ? (float) $row['longitude'] : null,
                'reportedAt'  => $row['reported_time'],
            ];
        }

        return $grouped;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Criticidade — ratio domina, delay absoluto só dá bônus
    // ─────────────────────────────────────────────────────────────────────

    /**
     * score = (ratio * 0.85) + (min(delay/300, 1) * 0.15)
     *   2min+1min  (50%) → 0.455 severe
     *   10min+1min (10%) → 0.115 moderate
     *
     * @return array{level:string,label:string,ratio:float,score:float}
     */
    private function computeDelayLevel(?int $delaySeconds, ?float $historicSeconds, ?int $jamLevel = null): array
    {
        if ($delaySeconds !== null && $delaySeconds > 0) {
            $historic = ($historicSeconds !== null && $historicSeconds > 0)
                ? $historicSeconds
                : 60.0;

            $ratio   = $delaySeconds / $historic;
            $absBump = min($delaySeconds / 300.0, 1.0);
            $score   = ($ratio * 0.85) + ($absBump * 0.15);

            if ($score >= 0.40) return ['level' => 'severe',   'label' => 'Crítico',  'ratio' => $ratio, 'score' => $score];
            if ($score >= 0.22) return ['level' => 'heavy',    'label' => 'Alto',     'ratio' => $ratio, 'score' => $score];
            if ($score >= 0.10) return ['level' => 'moderate', 'label' => 'Moderado', 'ratio' => $ratio, 'score' => $score];
            return                    ['level' => 'light',    'label' => 'Leve',     'ratio' => $ratio, 'score' => $score];
        }

        if ($jamLevel !== null) {
            return match ($jamLevel) {
                5       => ['level' => 'severe',   'label' => 'Parado',     'ratio' => 0.90, 'score' => 0.90],
                4       => ['level' => 'heavy',    'label' => 'Muito alto', 'ratio' => 0.60, 'score' => 0.60],
                3       => ['level' => 'heavy',    'label' => 'Alto',       'ratio' => 0.40, 'score' => 0.40],
                2       => ['level' => 'moderate', 'label' => 'Moderado',   'ratio' => 0.20, 'score' => 0.20],
                1       => ['level' => 'light',    'label' => 'Leve',       'ratio' => 0.08, 'score' => 0.08],
                default => ['level' => 'none',     'label' => 'No prazo',   'ratio' => 0.00, 'score' => 0.00],
            };
        }

        return ['level' => 'none', 'label' => 'No prazo', 'ratio' => 0.0, 'score' => 0.0];
    }

    /** @return list<array{0:float,1:float}> */
    private function normalizeLine(mixed $raw): array
    {
        if ($raw === null || $raw === '') return [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) return [];
            $raw = $decoded;
        }

        if (!is_array($raw) || $raw === []) return [];

        if (isset($raw['type'], $raw['coordinates'])
            && $raw['type'] === 'LineString'
            && is_array($raw['coordinates'])) {
            $out = [];
            foreach ($raw['coordinates'] as $c) {
                if (is_array($c) && count($c) >= 2) {
                    $out[] = [(float) $c[1], (float) $c[0]];
                }
            }
            return $this->simplifyLine($out);
        }

        $out = [];
        foreach ($raw as $point) {
            if (is_array($point) && isset($point['lat'], $point['lng'])) {
                $out[] = [(float) $point['lat'], (float) $point['lng']];
            } elseif (is_array($point) && isset($point['x'], $point['y'])) {
                $out[] = [(float) $point['y'], (float) $point['x']];
            } elseif (is_array($point) && count($point) >= 2) {
                $out[] = [(float) $point[1], (float) $point[0]];
            }
        }

        return $this->simplifyLine($out);
    }

    /** @param list<array{0:float,1:float}> $line @return list<array{0:float,1:float}> */
    private function simplifyLine(array $line, int $max = self::MAX_LINE_POINTS): array
    {
        $count = count($line);
        if ($count <= $max) return $line;

        $step = ($count - 1) / ($max - 1);
        $out  = [];
        for ($i = 0; $i < $max - 1; $i++) {
            $out[] = $line[(int) round($i * $step)];
        }
        $out[] = $line[$count - 1];

        return $out;
    }

    /** @param list<array<string,mixed>> $routes */
    private function computeStats(array $routes): array
    {
        $total = count($routes);
        if ($total === 0) {
            return [
                'total' => 0, 'delayed' => 0, 'severe' => 0, 'heavy' => 0,
                'avgDelaySeconds' => 0, 'avgDelayPercent' => 0.0, 'avgDelayScore' => 0.0,
                'worstRoute' => null, 'withIrregularities' => 0,
            ];
        }

        $delayed = 0; $severe = 0; $heavy = 0;
        $sumDelay = 0; $sumRatio = 0.0; $sumScore = 0.0; $countedDelay = 0;
        $worst = null; $withIrreg = 0;

        foreach ($routes as $r) {
            $level = $r['delayLevel'];
            if ($level === 'severe')   $severe++;
            if ($level === 'heavy')    $heavy++;
            if (($r['delaySeconds'] ?? 0) > 0) $delayed++;
            if (($r['irregularityCount'] ?? 0) > 0) $withIrreg++;

            if ($r['delaySeconds'] !== null) {
                $sumDelay += $r['delaySeconds'];
                $sumRatio += $r['delayRatio'] ?? 0;
                $sumScore += $r['delayScore'] ?? 0;
                $countedDelay++;

                $score = $r['delayScore'] ?? 0;
                if ($worst === null || $score > $worst['delayScore']) {
                    $worst = [
                        'id'           => $r['id'],
                        'name'         => $r['name'],
                        'delaySeconds' => $r['delaySeconds'],
                        'delayRatio'   => $r['delayRatio'],
                        'delayScore'   => $score,
                    ];
                }
            }
        }

        return [
            'total'               => $total,
            'delayed'             => $delayed,
            'severe'              => $severe,
            'heavy'               => $heavy,
            'avgDelaySeconds'     => $countedDelay > 0 ? (int) round($sumDelay / $countedDelay) : 0,
            'avgDelayPercent'     => $countedDelay > 0 ? $sumRatio / $countedDelay : 0.0,
            'avgDelayScore'       => $countedDelay > 0 ? $sumScore / $countedDelay : 0.0,
            'worstRoute'          => $worst,
            'withIrregularities'  => $withIrreg,
        ];
    }

    /** @param list<array<string,mixed>> $segments @return array{lat:float,lng:float,zoom:int} */
    private function computeCenter(array $segments): array
    {
        $sumLat = 0.0; $sumLng = 0.0; $count = 0;

        foreach ($segments as $seg) {
            foreach (($seg['path'] ?? []) as $pt) {
                if (!is_array($pt) || count($pt) < 2) continue;
                $sumLat += (float) $pt[0];
                $sumLng += (float) $pt[1];
                $count++;
            }
        }

        if ($count === 0) {
            return ['lat' => -20.6607, 'lng' => -43.7856, 'zoom' => 12];
        }

        return ['lat' => $sumLat / $count, 'lng' => $sumLng / $count, 'zoom' => 12];
    }
}
