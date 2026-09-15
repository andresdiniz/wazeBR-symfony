<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Comparação entre duas rotas TVT.
 *
 * Retorna o payload necessário pra tela /routes/compare, incluindo:
 *  - dados básicos + último snapshot das duas rotas
 *  - timeline sobreposta (7 dias)
 *  - heatmap diferencial dia×hora (ratioA - ratioB)
 *  - por hora do dia e por dia da semana (lado a lado)
 *  - trechos comuns (sub-rotas com mesmo from/to ou bbox próximo)
 *  - alertas cruzados: em ambas, só em A, só em B
 */
final class RouteCompareRepository
{
    private const WINDOW_DAYS     = 30;
    private const TIMELINE_DAYS   = 7;
    private const ALERT_BUFFER_M  = 60.0;
    private const ALERT_DAYS      = 7;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{
     *   a:array, b:array,
     *   timeline:list<array>,
     *   heatmapDiff:list<array>,
     *   byHour:list<array>,
     *   byDow:list<array>,
     *   commonSubRoutes:list<array>,
     *   alerts:array{shared:list,onlyA:list,onlyB:list},
     *   stats:array,
     *   map:array
     * }|null
     */
    public function compare(?Partner $partner, int $aId, int $bId): ?array
    {
        if ($aId === $bId) return null;

        $a = $this->loadRoute($partner, $aId);
        $b = $this->loadRoute($partner, $bId);
        if ($a === null || $b === null) return null;

        $aCurrent = $this->loadCurrentSnapshot($partner, $aId);
        $bCurrent = $this->loadCurrentSnapshot($partner, $bId);

        $aTimeline = $this->loadTimeline($partner, $aId, self::TIMELINE_DAYS);
        $bTimeline = $this->loadTimeline($partner, $bId, self::TIMELINE_DAYS);
        $timeline = $this->mergeTimelines($aTimeline, $bTimeline);

        $aHeat = $this->loadHeatmap($partner, $aId, self::WINDOW_DAYS);
        $bHeat = $this->loadHeatmap($partner, $bId, self::WINDOW_DAYS);
        $heatmapDiff = $this->computeHeatmapDiff($aHeat, $bHeat);

        $aByHour = $this->loadByHour($partner, $aId, self::WINDOW_DAYS);
        $bByHour = $this->loadByHour($partner, $bId, self::WINDOW_DAYS);
        $byHour  = $this->mergeByHour($aByHour, $bByHour);

        $aByDow  = $this->loadByDow($partner, $aId, self::WINDOW_DAYS);
        $bByDow  = $this->loadByDow($partner, $bId, self::WINDOW_DAYS);
        $byDow   = $this->mergeByDow($aByDow, $bByDow);

        $aSubs = $this->loadSubRoutes($partner, $aId);
        $bSubs = $this->loadSubRoutes($partner, $bId);
        $commonSubRoutes = $this->findCommonSubRoutes($aSubs, $bSubs);

        $aPolyline = $this->buildPolyline($partner, $aId, $a['geometry']);
        $bPolyline = $this->buildPolyline($partner, $bId, $b['geometry']);
        $alerts = $this->loadAlertsCross($partner, $aPolyline, $bPolyline);

        $aStats = $this->computeStats($aTimeline, $aByHour, $aByDow, $aCurrent);
        $bStats = $this->computeStats($bTimeline, $bByHour, $bByDow, $bCurrent);

        return [
            'a' => [
                'id'             => $a['id'],
                'wazeRouteId'    => $a['waze_route_id'],
                'name'           => $a['name'],
                'from'           => $a['from_name'],
                'to'             => $a['to_name'],
                'lengthMeters'   => $a['length'] !== null ? (int) $a['length'] : null,
                'current'        => $aCurrent,
                'stats'          => $aStats,
                'geometry'       => $aPolyline,
            ],
            'b' => [
                'id'             => $b['id'],
                'wazeRouteId'    => $b['waze_route_id'],
                'name'           => $b['name'],
                'from'           => $b['from_name'],
                'to'             => $b['to_name'],
                'lengthMeters'   => $b['length'] !== null ? (int) $b['length'] : null,
                'current'        => $bCurrent,
                'stats'          => $bStats,
                'geometry'       => $bPolyline,
            ],
            'timeline'        => $timeline,
            'heatmapDiff'     => $heatmapDiff,
            'byHour'          => $byHour,
            'byDow'           => $byDow,
            'commonSubRoutes' => $commonSubRoutes,
            'alerts'          => $alerts,
            'stats'           => [
                'winnerNow'   => $this->pickWinner($aCurrent, $bCurrent),
                'winner7d'    => $aStats['avgRatio7d'] !== null && $bStats['avgRatio7d'] !== null
                    ? ($aStats['avgRatio7d'] < $bStats['avgRatio7d'] ? 'a' : 'b')
                    : null,
                'pointsA'     => $this->comparePoints($aStats, $bStats, 'a'),
                'pointsB'     => $this->comparePoints($aStats, $bStats, 'b'),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadRoute(?Partner $partner, int $id): ?array
    {
        $params = ['id' => $id];
        $pf = '';
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }
        $row = $this->connection->executeQuery(
            "SELECT id, route_id AS waze_route_id, name, from_name, to_name, length, geometry
             FROM waze_tvt_route WHERE id = :id {$pf} LIMIT 1",
            $params
        )->fetchAssociative();
        return $row ?: null;
    }

    private function loadCurrentSnapshot(?Partner $partner, int $routeId): ?array
    {
        $params = ['id' => $routeId];
        $pf = '';
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }
        $row = $this->connection->executeQuery(
            "SELECT time, historic_time, jam_level, recorded_at
             FROM waze_tvt_route_snapshot
             WHERE route_id = :id {$pf}
             ORDER BY recorded_at DESC, id DESC
             LIMIT 1",
            $params
        )->fetchAssociative();
        if (!$row) return null;

        $time     = $row['time'] !== null ? (float) $row['time'] : null;
        $historic = $row['historic_time'] !== null ? (float) $row['historic_time'] : null;
        $delay    = ($time !== null && $historic !== null) ? max(0, (int) round($time - $historic)) : null;
        $ratio    = ($delay !== null && $historic > 0) ? $delay / $historic : null;

        return [
            'time'         => $time,
            'historicTime' => $historic,
            'delaySeconds' => $delay,
            'delayRatio'   => $ratio,
            'jamLevel'     => $row['jam_level'] !== null ? (int) $row['jam_level'] : null,
            'recordedAt'   => $row['recorded_at'],
        ];
    }

    /** @return list<array{time:string,avgDelay:float,avgRatio:?float,count:int}> */
    private function loadTimeline(?Partner $partner, int $routeId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $params = ['id' => $routeId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $sql = "SELECT
                    DATE_FORMAT(DATE_ADD(recorded_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    AVG(time - historic_time) AS avg_delay,
                    AVG(CASE WHEN historic_time > 0 THEN (time - historic_time) / historic_time ELSE NULL END) AS avg_ratio,
                    COUNT(*) AS total
                FROM waze_tvt_route_snapshot
                WHERE route_id = :id
                  AND recorded_at >= :since
                  AND time IS NOT NULL AND historic_time IS NOT NULL
                  {$pf}
                GROUP BY bucket ORDER BY bucket ASC LIMIT 500";

        return array_map(static fn ($r) => [
            'time'     => (string) $r['bucket'],
            'avgDelay' => $r['avg_delay'] !== null ? round((float) $r['avg_delay'], 1) : 0.0,
            'avgRatio' => $r['avg_ratio'] !== null ? round((float) $r['avg_ratio'], 4) : null,
            'count'    => (int) $r['total'],
        ], $this->connection->executeQuery($sql, $params)->fetchAllAssociative());
    }

    /** @return list<array{dow:int,hour:int,avgRatio:?float,avgDelay:?float,count:int}> */
    private function loadHeatmap(?Partner $partner, int $routeId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $params = ['id' => $routeId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $sql = "SELECT
                    WEEKDAY(DATE_ADD(recorded_at, INTERVAL -3 HOUR)) AS dow,
                    HOUR(DATE_ADD(recorded_at, INTERVAL -3 HOUR))    AS hour,
                    AVG(CASE WHEN historic_time > 0 THEN (time - historic_time) / historic_time ELSE NULL END) AS avg_ratio,
                    AVG(time - historic_time) AS avg_delay,
                    COUNT(*) AS total
                FROM waze_tvt_route_snapshot
                WHERE route_id = :id
                  AND recorded_at >= :since
                  AND time IS NOT NULL AND historic_time IS NOT NULL
                  {$pf}
                GROUP BY dow, hour";

        return array_map(static fn ($r) => [
            'dow'      => (int) $r['dow'],
            'hour'     => (int) $r['hour'],
            'avgRatio' => $r['avg_ratio'] !== null ? round((float) $r['avg_ratio'], 4) : null,
            'avgDelay' => $r['avg_delay'] !== null ? round((float) $r['avg_delay'], 1) : null,
            'count'    => (int) $r['total'],
        ], $this->connection->executeQuery($sql, $params)->fetchAllAssociative());
    }

    private function loadByHour(?Partner $partner, int $routeId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $params = ['id' => $routeId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $sql = "SELECT
                    HOUR(DATE_ADD(recorded_at, INTERVAL -3 HOUR)) AS hour,
                    AVG(CASE WHEN historic_time > 0 THEN (time - historic_time) / historic_time ELSE NULL END) AS avg_ratio,
                    AVG(time - historic_time) AS avg_delay,
                    COUNT(*) AS total
                FROM waze_tvt_route_snapshot
                WHERE route_id = :id AND recorded_at >= :since
                  AND time IS NOT NULL AND historic_time IS NOT NULL {$pf}
                GROUP BY hour";
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($rows as $r) $map[(int) $r['hour']] = $r;
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $r = $map[$h] ?? null;
            $out[] = [
                'hour'     => $h,
                'avgRatio' => $r && $r['avg_ratio'] !== null ? round((float) $r['avg_ratio'], 4) : null,
                'avgDelay' => $r && $r['avg_delay'] !== null ? round((float) $r['avg_delay'], 1) : null,
                'count'    => $r ? (int) $r['total'] : 0,
            ];
        }
        return $out;
    }

    private function loadByDow(?Partner $partner, int $routeId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $params = ['id' => $routeId, 'since' => $since];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $sql = "SELECT
                    WEEKDAY(DATE_ADD(recorded_at, INTERVAL -3 HOUR)) AS dow,
                    AVG(CASE WHEN historic_time > 0 THEN (time - historic_time) / historic_time ELSE NULL END) AS avg_ratio,
                    AVG(time - historic_time) AS avg_delay,
                    COUNT(*) AS total
                FROM waze_tvt_route_snapshot
                WHERE route_id = :id AND recorded_at >= :since
                  AND time IS NOT NULL AND historic_time IS NOT NULL {$pf}
                GROUP BY dow";
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($rows as $r) $map[(int) $r['dow']] = $r;
        $out = [];
        for ($d = 0; $d < 7; $d++) {
            $r = $map[$d] ?? null;
            $out[] = [
                'dow'      => $d,
                'avgRatio' => $r && $r['avg_ratio'] !== null ? round((float) $r['avg_ratio'], 4) : null,
                'avgDelay' => $r && $r['avg_delay'] !== null ? round((float) $r['avg_delay'], 1) : null,
                'count'    => $r ? (int) $r['total'] : 0,
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function loadSubRoutes(?Partner $partner, int $routeId): array
    {
        $params = ['id' => $routeId];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT id, name, from_name, to_name, time, historic_time, jam_level, bbox
             FROM waze_tvt_sub_route
             WHERE route_id = :id AND is_active = 1 {$pf}
             ORDER BY id ASC",
            $params
        )->fetchAllAssociative();

        return array_map(function ($r) {
            $time = $r['time'] !== null ? (float) $r['time'] : null;
            $hist = $r['historic_time'] !== null ? (float) $r['historic_time'] : null;
            $delay = ($time !== null && $hist !== null) ? max(0, (int) round($time - $hist)) : null;
            $ratio = ($delay !== null && $hist > 0) ? $delay / $hist : null;

            return [
                'id'           => (int) $r['id'],
                'name'         => $r['name'],
                'from'         => $r['from_name'],
                'to'           => $r['to_name'],
                'delaySeconds' => $delay,
                'delayRatio'   => $ratio,
                'jamLevel'     => $r['jam_level'] !== null ? (int) $r['jam_level'] : null,
                'bbox'         => $r['bbox'],
            ];
        }, $rows);
    }

    /**
     * Trechos comuns: sub-rotas de A que "casam" com sub-rotas de B.
     * Casamento = mesmo `from` normalizado OU mesmo `to` normalizado,
     * com pelo menos 4 caracteres em comum.
     *
     * @return list<array{a:array,b:array,matched:string}>
     */
    private function findCommonSubRoutes(array $aSubs, array $bSubs): array
    {
        $out = [];
        foreach ($aSubs as $a) {
            foreach ($bSubs as $b) {
                $match = null;
                if ($this->similarPlace($a['from'], $b['from']))      $match = 'from';
                elseif ($this->similarPlace($a['to'], $b['to']))      $match = 'to';
                elseif ($this->similarPlace($a['from'], $b['to']))    $match = 'from→to';
                elseif ($this->similarPlace($a['to'], $b['from']))    $match = 'to→from';

                if ($match !== null) {
                    $out[] = ['a' => $a, 'b' => $b, 'matched' => $match];
                    break;
                }
            }
        }
        return $out;
    }

    private function similarPlace(?string $x, ?string $y): bool
    {
        if ($x === null || $y === null) return false;
        $nx = mb_strtolower(trim($x));
        $ny = mb_strtolower(trim($y));
        if ($nx === '' || $ny === '') return false;
        if ($nx === $ny) return true;
        // Compara tokens significativos (>= 5 chars)
        $tx = preg_split('/\W+/u', $nx) ?: [];
        $ty = preg_split('/\W+/u', $ny) ?: [];
        $tx = array_filter($tx, fn ($t) => mb_strlen($t) >= 5);
        $ty = array_filter($ty, fn ($t) => mb_strlen($t) >= 5);
        return count(array_intersect($tx, $ty)) > 0;
    }

    /** @return list<array{0:float,1:float}> */
    private function buildPolyline(?Partner $partner, int $routeId, mixed $routeGeometry): array
    {
        $poly = $this->normalizeLine($routeGeometry);

        $params = ['id' => $routeId];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT line FROM waze_tvt_sub_route
             WHERE route_id = :id AND is_active = 1 AND line IS NOT NULL {$pf}",
            $params
        )->fetchAllAssociative();

        foreach ($rows as $r) {
            foreach ($this->normalizeLine($r['line']) as $pt) $poly[] = $pt;
        }
        return $poly;
    }

    /**
     * Alertas Waze nos últimos N dias que estão perto de A, de B, ou das duas.
     *
     * @return array{shared:list,onlyA:list,onlyB:list}
     */
    private function loadAlertsCross(?Partner $partner, array $aPoly, array $bPoly): array
    {
        $shared = []; $onlyA = []; $onlyB = [];
        if ($aPoly === [] && $bPoly === []) {
            return ['shared' => $shared, 'onlyA' => $onlyA, 'onlyB' => $onlyB];
        }

        // Bbox que cobre as duas
        $all = array_merge($aPoly, $bPoly);
        if ($all === []) return ['shared' => [], 'onlyA' => [], 'onlyB' => []];
        $minLat = $maxLat = $all[0][0];
        $minLng = $maxLng = $all[0][1];
        foreach ($all as $p) {
            $minLat = min($minLat, $p[0]); $maxLat = max($maxLat, $p[0]);
            $minLng = min($minLng, $p[1]); $maxLng = max($maxLng, $p[1]);
        }
        $pad = 0.002;

        $since = (new \DateTimeImmutable('-'.self::ALERT_DAYS.' days', new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $params = [
            'since'  => $since,
            'minLat' => $minLat - $pad, 'maxLat' => $maxLat + $pad,
            'minLng' => $minLng - $pad, 'maxLng' => $maxLng + $pad,
        ];
        $pf = '';
        if ($partner !== null) { $pf = ' AND partner_id = :pid'; $params['pid'] = $partner->getId(); }

        $rows = $this->connection->executeQuery(
            "SELECT id, type, subtype, street, city, confidence, reliability,
                    CAST(latitude AS DECIMAL(10,7)) AS lat,
                    CAST(longitude AS DECIMAL(10,7)) AS lng,
                    pub_millis, collected_at
             FROM waze_alerts
             WHERE collected_at >= :since
               AND latitude  BETWEEN :minLat AND :maxLat
               AND longitude BETWEEN :minLng AND :maxLng
               {$pf}
             ORDER BY collected_at DESC
             LIMIT 400",
            $params
        )->fetchAllAssociative();

        foreach ($rows as $r) {
            $lat = (float) $r['lat'];
            $lng = (float) $r['lng'];

            $dA = $aPoly !== [] ? $this->distanceToPolyline($lat, $lng, $aPoly) : INF;
            $dB = $bPoly !== [] ? $this->distanceToPolyline($lat, $lng, $bPoly) : INF;

            $inA = $dA <= self::ALERT_BUFFER_M;
            $inB = $dB <= self::ALERT_BUFFER_M;
            if (!$inA && !$inB) continue;

            $item = [
                'id'             => (int) $r['id'],
                'type'           => $r['type'],
                'subtype'        => $r['subtype'],
                'street'         => $r['street'],
                'city'           => $r['city'],
                'confidence'     => (int) $r['confidence'],
                'reliability'    => (int) $r['reliability'],
                'lat'            => $lat,
                'lng'            => $lng,
                'distanceA'      => $inA ? (int) round($dA) : null,
                'distanceB'      => $inB ? (int) round($dB) : null,
                'pubDateTime'    => $r['pub_millis']
                    ? (new \DateTimeImmutable('@'.intdiv((int) $r['pub_millis'], 1000)))->format(DATE_ATOM)
                    : null,
                'collectedAt'    => $r['collected_at'],
                'typeLabel'      => $this->labelAlertType((string) $r['type']),
            ];

            if ($inA && $inB)      $shared[] = $item;
            elseif ($inA)          $onlyA[]  = $item;
            else                   $onlyB[]  = $item;
        }

        return ['shared' => $shared, 'onlyA' => $onlyA, 'onlyB' => $onlyB];
    }

    private function distanceToPolyline(float $lat, float $lng, array $polyline): float
    {
        $n = count($polyline);
        if ($n === 0) return INF;
        if ($n === 1) return $this->segDist($lat, $lng, $polyline[0][0], $polyline[0][1], $polyline[0][0], $polyline[0][1]);
        $min = INF;
        for ($i = 0; $i < $n - 1; $i++) {
            $d = $this->segDist($lat, $lng,
                $polyline[$i][0], $polyline[$i][1],
                $polyline[$i + 1][0], $polyline[$i + 1][1]);
            if ($d < $min) $min = $d;
        }
        return $min;
    }

    private function segDist(float $lat, float $lng, float $latA, float $lngA, float $latB, float $lngB): float
    {
        $cosLat = cos(deg2rad($lat));
        $px = $lng * $cosLat; $py = $lat;
        $ax = $lngA * $cosLat; $ay = $latA;
        $bx = $lngB * $cosLat; $by = $latB;
        $dx = $bx - $ax; $dy = $by - $ay;
        $lenSq = $dx * $dx + $dy * $dy;
        $t = $lenSq <= 0.0 ? 0.0 : max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $lenSq));
        $cx = $ax + $t * $dx; $cy = $ay + $t * $dy;
        $ddx = $px - $cx; $ddy = $py - $cy;
        return sqrt($ddx * $ddx + $ddy * $ddy) * 111320.0;
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
                if (is_array($c) && count($c) >= 2) $out[] = [(float) $c[1], (float) $c[0]];
            }
            return $out;
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
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Merges / diffs
    // ─────────────────────────────────────────────────────────────────────

    private function mergeTimelines(array $a, array $b): array
    {
        $map = [];
        foreach ($a as $p) $map[$p['time']] = ['time' => $p['time'], 'aDelay' => $p['avgDelay'], 'aRatio' => $p['avgRatio'], 'bDelay' => null, 'bRatio' => null];
        foreach ($b as $p) {
            if (!isset($map[$p['time']])) $map[$p['time']] = ['time' => $p['time'], 'aDelay' => null, 'aRatio' => null, 'bDelay' => null, 'bRatio' => null];
            $map[$p['time']]['bDelay'] = $p['avgDelay'];
            $map[$p['time']]['bRatio'] = $p['avgRatio'];
        }
        ksort($map);
        return array_values($map);
    }

    /** @return list<array{dow:int,hour:int,aRatio:?float,bRatio:?float,diff:?float,aDelay:?float,bDelay:?float}> */
    private function computeHeatmapDiff(array $aHeat, array $bHeat): array
    {
        $aMap = [];
        foreach ($aHeat as $c) $aMap["{$c['dow']}:{$c['hour']}"] = $c;
        $bMap = [];
        foreach ($bHeat as $c) $bMap["{$c['dow']}:{$c['hour']}"] = $c;

        $out = [];
        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $k = "{$d}:{$h}";
                $a = $aMap[$k] ?? null;
                $b = $bMap[$k] ?? null;
                $aRatio = ($a && $a['count'] >= 2) ? $a['avgRatio'] : null;
                $bRatio = ($b && $b['count'] >= 2) ? $b['avgRatio'] : null;
                $diff = ($aRatio !== null && $bRatio !== null) ? round($aRatio - $bRatio, 4) : null;

                $out[] = [
                    'dow'      => $d,
                    'hour'     => $h,
                    'aRatio'   => $aRatio,
                    'bRatio'   => $bRatio,
                    'diff'     => $diff,
                    'aDelay'   => $a ? $a['avgDelay'] : null,
                    'bDelay'   => $b ? $b['avgDelay'] : null,
                ];
            }
        }
        return $out;
    }

    private function mergeByHour(array $a, array $b): array
    {
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = [
                'hour'   => $h,
                'aRatio' => $a[$h]['avgRatio'] ?? null,
                'bRatio' => $b[$h]['avgRatio'] ?? null,
                'aDelay' => $a[$h]['avgDelay'] ?? null,
                'bDelay' => $b[$h]['avgDelay'] ?? null,
            ];
        }
        return $out;
    }

    private function mergeByDow(array $a, array $b): array
    {
        $out = [];
        for ($d = 0; $d < 7; $d++) {
            $out[] = [
                'dow'    => $d,
                'aRatio' => $a[$d]['avgRatio'] ?? null,
                'bRatio' => $b[$d]['avgRatio'] ?? null,
                'aDelay' => $a[$d]['avgDelay'] ?? null,
                'bDelay' => $b[$d]['avgDelay'] ?? null,
            ];
        }
        return $out;
    }

    private function computeStats(array $timeline, array $byHour, array $byDow, ?array $current): array
    {
        $avgR7d = null; $avgD7d = null; $avgR24h = null; $avgD24h = null;

        if ($timeline !== []) {
            $sumR = 0.0; $sumD = 0.0; $n = 0;
            foreach ($timeline as $p) {
                if ($p['avgRatio'] !== null) { $sumR += $p['avgRatio']; $n++; }
                if ($p['avgDelay'] !== null) { $sumD += $p['avgDelay']; }
            }
            if ($n > 0) { $avgR7d = $sumR / $n; $avgD7d = $sumD / $n; }

            $cutoff = time() - 86400;
            $sumR = 0.0; $sumD = 0.0; $n = 0;
            foreach ($timeline as $p) {
                $ts = strtotime((string) $p['time'].' UTC');
                if ($ts === false || $ts < $cutoff) continue;
                if ($p['avgRatio'] !== null) { $sumR += $p['avgRatio']; $n++; }
                if ($p['avgDelay'] !== null) { $sumD += $p['avgDelay']; }
            }
            if ($n > 0) { $avgR24h = $sumR / $n; $avgD24h = $sumD / $n; }
        }

        $worstHour = null;
        foreach ($byHour as $p) {
            if ($p['avgRatio'] === null || $p['count'] < 2) continue;
            if ($worstHour === null || $p['avgRatio'] > $worstHour['avgRatio']) $worstHour = $p;
        }
        $worstDow = null;
        foreach ($byDow as $p) {
            if ($p['avgRatio'] === null || $p['count'] < 2) continue;
            if ($worstDow === null || $p['avgRatio'] > $worstDow['avgRatio']) $worstDow = $p;
        }

        return [
            'avgDelay24h' => $avgD24h !== null ? round($avgD24h, 1) : null,
            'avgRatio24h' => $avgR24h,
            'avgDelay7d'  => $avgD7d !== null ? round($avgD7d, 1) : null,
            'avgRatio7d'  => $avgR7d,
            'worstHour'   => $worstHour,
            'worstDow'    => $worstDow,
            'current'     => $current,
        ];
    }

    private function pickWinner(?array $a, ?array $b): ?string
    {
        $ra = $a['delayRatio'] ?? null;
        $rb = $b['delayRatio'] ?? null;
        if ($ra === null && $rb === null) return null;
        if ($ra === null) return 'b';
        if ($rb === null) return 'a';
        if (abs($ra - $rb) < 0.05) return 'tie';
        return $ra < $rb ? 'a' : 'b';
    }

    private function comparePoints(array $a, array $b, string $side): int
    {
        $points = 0;
        if ($a['avgRatio7d'] !== null && $b['avgRatio7d'] !== null) {
            if ($side === 'a' ? $a['avgRatio7d'] < $b['avgRatio7d'] : $b['avgRatio7d'] < $a['avgRatio7d']) $points++;
        }
        if ($a['avgRatio24h'] !== null && $b['avgRatio24h'] !== null) {
            if ($side === 'a' ? $a['avgRatio24h'] < $b['avgRatio24h'] : $b['avgRatio24h'] < $a['avgRatio24h']) $points++;
        }
        if ($a['worstHour'] && $b['worstHour']) {
            if ($side === 'a' ? $a['worstHour']['avgRatio'] < $b['worstHour']['avgRatio'] : $b['worstHour']['avgRatio'] < $a['worstHour']['avgRatio']) $points++;
        }
        if ($a['worstDow'] && $b['worstDow']) {
            if ($side === 'a' ? $a['worstDow']['avgRatio'] < $b['worstDow']['avgRatio'] : $b['worstDow']['avgRatio'] < $a['worstDow']['avgRatio']) $points++;
        }
        return $points;
    }

    private function labelAlertType(string $type): string
    {
        return match (strtoupper($type)) {
            'HAZARD'        => 'Perigo',
            'ROAD_CLOSED'   => 'Via fechada',
            'ACCIDENT'      => 'Acidente',
            'JAM'           => 'Congestionamento',
            'POLICE'        => 'Polícia',
            'WEATHERHAZARD' => 'Perigo climático',
            'CONSTRUCTION'  => 'Obras',
            default         => ucfirst(strtolower($type)) ?: 'Alerta',
        };
    }
}
