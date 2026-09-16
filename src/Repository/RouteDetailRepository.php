<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;

/**
 * Agregação para a página de detalhe de uma rota TVT.
 *
 * ─── Critério de criticidade (heatmap, sub-rotas, KPIs) ──────────────
 *
 * O cálculo de "quão ruim está" é baseado em RATIO, não em delay absoluto:
 *
 *     ratio = (time - historic_time) / historic_time
 *
 * Exemplos:
 *   - 180s normal, +2s atual  → ratio = 1.1%   → verde
 *   -  20s normal, +2s atual  → ratio = 10%    → amarelo
 *   - 180s normal, +90s atual → ratio = 50%    → vermelho
 *
 * Um delay absoluto pequeno numa rota longa é tolerável; o mesmo delay
 * numa rota curta é grave. Por isso ratio domina.
 */
final class RouteDetailRepository
{
    private const WINDOW_DAYS   = 30;
    private const TIMELINE_DAYS = 7;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{
     *   route:array,
     *   current:?array,
     *   stats:array,
     *   heatmap:list<array>,
     *   timeline:list<array>,
     *   byHour:list<array>,
     *   byDow:list<array>,
     *   jamDistribution:list<array>,
     *   topSubRoutes:list<array>,
     *   irregularities:list<array>,
     *   nearbyAlerts:list<array>,
     *   routePolyline:list<array>,
     *   subRoutesPolyline:list<array>
     * }|null
     */
    public function getRouteDetail(?Partner $partner, int $routeId): ?array
    {
        $route = $this->loadRoute($partner, $routeId);
        if ($route === null) {
            return null;
        }

        $current          = $this->loadCurrentSnapshot($partner, $routeId);
        $timeline         = $this->loadTimeline($partner, $routeId, self::TIMELINE_DAYS);
        $heatmap          = $this->loadHeatmap($partner, $routeId, self::WINDOW_DAYS);
        $byHour           = $this->loadByHour($partner, $routeId, self::WINDOW_DAYS);
        $byDow            = $this->loadByDow($partner, $routeId, self::WINDOW_DAYS);
        $jamDistribution  = $this->loadJamDistribution($partner, $routeId, self::WINDOW_DAYS);
        $topSubRoutes     = $this->loadTopSubRoutes($partner, $routeId);
        $irregularities   = $this->loadRecentIrregularities($partner, $routeId);
        $nearbyAlerts     = $this->loadNearbyAlerts($partner, $routeId, 7, 60.0);
        $routePolyline    = $this->loadRoutePolyline($partner, $routeId);

        // Sub-rotas com polyline própria (pra mini-mapas)
        $subRoutesPolyline = $this->loadSubRoutesPolyline($partner, $routeId);

        return [
            'route'             => $route,
            'current'           => $current,
            'stats'             => $this->computeStats($timeline, $heatmap, $byHour, $byDow, $current),
            'heatmap'           => $heatmap,
            'timeline'          => $timeline,
            'byHour'            => $byHour,
            'byDow'             => $byDow,
            'jamDistribution'   => $jamDistribution,
            'topSubRoutes'      => $topSubRoutes,
            'irregularities'    => $irregularities,
            'nearbyAlerts'      => $nearbyAlerts,
            'routePolyline'     => $routePolyline,
            'subRoutesPolyline' => $subRoutesPolyline,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function loadRoute(?Partner $partner, int $routeId): ?array
    {
        $params        = ['id' => $routeId];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND r.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT
                    r.id, r.route_id AS waze_route_id, r.name, r.from_name, r.to_name,
                    r.length, r.is_active, r.last_seen_at, r.deactivated_at
                FROM waze_tvt_route r
                WHERE r.id = :id {$partnerFilter}
                LIMIT 1";

        $row = $this->connection->executeQuery($sql, $params)->fetchAssociative();
        if (!$row) return null;

        return [
            'id'           => (int) $row['id'],
            'wazeRouteId'  => (string) $row['waze_route_id'],
            'name'         => $row['name'] ?: 'Rota monitorada',
            'from'         => $row['from_name'],
            'to'           => $row['to_name'],
            'lengthMeters' => $row['length'] !== null ? (int) $row['length'] : null,
            'isActive'     => (bool) $row['is_active'],
            'lastSeenAt'   => $row['last_seen_at'],
            'deactivatedAt'=> $row['deactivated_at'],
        ];
    }

    private function loadCurrentSnapshot(?Partner $partner, int $routeId): ?array
    {
        $params        = ['id' => $routeId];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND s.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT
                    s.id, s.name, s.city, s.state,
                    s.time, s.historic_time, s.jam_level,
                    s.recorded_at
                FROM waze_tvt_route_snapshot s
                WHERE s.route_id = :id {$partnerFilter}
                ORDER BY s.recorded_at DESC, s.id DESC
                LIMIT 1";

        $row = $this->connection->executeQuery($sql, $params)->fetchAssociative();
        if (!$row) return null;

        $time     = $row['time']          !== null ? (float) $row['time']          : null;
        $historic = $row['historic_time'] !== null ? (float) $row['historic_time'] : null;

        $delay = ($time !== null && $historic !== null)
            ? max(0, (int) round($time - $historic))
            : null;

        $ratio = ($delay !== null && $historic !== null && $historic > 0)
            ? $delay / $historic
            : null;

        return [
            'name'         => $row['name'],
            'city'         => $row['city'],
            'state'        => $row['state'],
            'time'         => $time,
            'historicTime' => $historic,
            'delaySeconds' => $delay,
            'delayRatio'   => $ratio,
            'jamLevel'     => $row['jam_level'] !== null ? (int) $row['jam_level'] : null,
            'recordedAt'   => $row['recorded_at'],
        ];
    }

    /** @return list<array{time:string,avgDelay:float,avgRatio:float,count:int,avgJam:?float}> */
    private function loadTimeline(?Partner $partner, int $routeId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $params = ['id' => $routeId, 'since' => $since];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND s.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT
                    DATE_FORMAT(DATE_ADD(s.recorded_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    AVG(s.time - s.historic_time) AS avg_delay,
                    AVG(CASE WHEN s.historic_time > 0
                             THEN (s.time - s.historic_time) / s.historic_time
                             ELSE NULL END) AS avg_ratio,
                    AVG(s.jam_level) AS avg_jam,
                    COUNT(*) AS total
                FROM waze_tvt_route_snapshot s
                WHERE s.route_id = :id
                  AND s.recorded_at >= :since
                  AND s.time IS NOT NULL
                  AND s.historic_time IS NOT NULL
                  {$partnerFilter}
                GROUP BY bucket
                ORDER BY bucket ASC
                LIMIT 500";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'time'     => (string) $r['bucket'],
            'avgDelay' => $r['avg_delay'] !== null ? round((float) $r['avg_delay'], 1) : 0.0,
            'avgRatio' => $r['avg_ratio'] !== null ? round((float) $r['avg_ratio'], 4) : null,
            'avgJam'   => $r['avg_jam']   !== null ? round((float) $r['avg_jam'], 2)   : null,
            'count'    => (int) $r['total'],
        ], $rows);
    }

    /**
     * Heatmap: ratio puro (time - historic) / historic por (dow, hour).
     * É a métrica que responde à pergunta "quão ruim comparado ao normal".
     *
     * @return list<array{dow:int,hour:int,avgRatio:?float,avgDelay:?float,count:int,avgJam:?float}>
     */
    private function loadHeatmap(?Partner $partner, int $routeId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $params = ['id' => $routeId, 'since' => $since];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND s.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT
                    WEEKDAY(DATE_ADD(s.recorded_at, INTERVAL -3 HOUR)) AS dow,
                    HOUR(DATE_ADD(s.recorded_at, INTERVAL -3 HOUR))    AS hour,
                    AVG(CASE WHEN s.historic_time > 0
                             THEN (s.time - s.historic_time) / s.historic_time
                             ELSE NULL END) AS avg_ratio,
                    AVG(s.time - s.historic_time) AS avg_delay,
                    AVG(s.jam_level) AS avg_jam,
                    COUNT(*) AS total
                FROM waze_tvt_route_snapshot s
                WHERE s.route_id = :id
                  AND s.recorded_at >= :since
                  AND s.time IS NOT NULL
                  AND s.historic_time IS NOT NULL
                  {$partnerFilter}
                GROUP BY dow, hour
                ORDER BY dow, hour";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'dow'      => (int) $r['dow'],
            'hour'     => (int) $r['hour'],
            'avgRatio' => $r['avg_ratio'] !== null ? round((float) $r['avg_ratio'], 4) : null,
            'avgDelay' => $r['avg_delay'] !== null ? round((float) $r['avg_delay'], 1) : null,
            'avgJam'   => $r['avg_jam']   !== null ? round((float) $r['avg_jam'], 2)   : null,
            'count'    => (int) $r['total'],
        ], $rows);
    }

    /** @return list<array{hour:int,avgRatio:?float,avgDelay:?float,count:int}> */
    private function loadByHour(?Partner $partner, int $routeId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $params = ['id' => $routeId, 'since' => $since];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND s.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT
                    HOUR(DATE_ADD(s.recorded_at, INTERVAL -3 HOUR)) AS hour,
                    AVG(CASE WHEN s.historic_time > 0
                             THEN (s.time - s.historic_time) / s.historic_time
                             ELSE NULL END) AS avg_ratio,
                    AVG(s.time - s.historic_time) AS avg_delay,
                    COUNT(*) AS total
                FROM waze_tvt_route_snapshot s
                WHERE s.route_id = :id
                  AND s.recorded_at >= :since
                  AND s.time IS NOT NULL
                  AND s.historic_time IS NOT NULL
                  {$partnerFilter}
                GROUP BY hour
                ORDER BY hour";

        $map = [];
        foreach ($this->connection->executeQuery($sql, $params)->fetchAllAssociative() as $r) {
            $map[(int) $r['hour']] = $r;
        }

        $byHour = [];
        for ($h = 0; $h < 24; $h++) {
            $r = $map[$h] ?? null;
            $byHour[] = [
                'hour'     => $h,
                'avgRatio' => $r && $r['avg_ratio'] !== null ? round((float) $r['avg_ratio'], 4) : null,
                'avgDelay' => $r && $r['avg_delay'] !== null ? round((float) $r['avg_delay'], 1) : null,
                'count'    => $r ? (int) $r['total'] : 0,
            ];
        }
        return $byHour;
    }

    /** @return list<array{dow:int,avgRatio:?float,avgDelay:?float,count:int}> */
    private function loadByDow(?Partner $partner, int $routeId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $params = ['id' => $routeId, 'since' => $since];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND s.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT
                    WEEKDAY(DATE_ADD(s.recorded_at, INTERVAL -3 HOUR)) AS dow,
                    AVG(CASE WHEN s.historic_time > 0
                             THEN (s.time - s.historic_time) / s.historic_time
                             ELSE NULL END) AS avg_ratio,
                    AVG(s.time - s.historic_time) AS avg_delay,
                    COUNT(*) AS total
                FROM waze_tvt_route_snapshot s
                WHERE s.route_id = :id
                  AND s.recorded_at >= :since
                  AND s.time IS NOT NULL
                  AND s.historic_time IS NOT NULL
                  {$partnerFilter}
                GROUP BY dow
                ORDER BY dow";

        $map = [];
        foreach ($this->connection->executeQuery($sql, $params)->fetchAllAssociative() as $r) {
            $map[(int) $r['dow']] = $r;
        }

        $byDow = [];
        for ($d = 0; $d < 7; $d++) {
            $r = $map[$d] ?? null;
            $byDow[] = [
                'dow'      => $d,
                'avgRatio' => $r && $r['avg_ratio'] !== null ? round((float) $r['avg_ratio'], 4) : null,
                'avgDelay' => $r && $r['avg_delay'] !== null ? round((float) $r['avg_delay'], 1) : null,
                'count'    => $r ? (int) $r['total'] : 0,
            ];
        }
        return $byDow;
    }

    /** @return list<array{level:int,count:int}> */
    private function loadJamDistribution(?Partner $partner, int $routeId, int $days): array
    {
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $params = ['id' => $routeId, 'since' => $since];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND s.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT s.jam_level, COUNT(*) AS total
                FROM waze_tvt_route_snapshot s
                WHERE s.route_id = :id
                  AND s.recorded_at >= :since
                  AND s.jam_level IS NOT NULL
                  {$partnerFilter}
                GROUP BY s.jam_level
                ORDER BY s.jam_level";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        $map = [];
        foreach ($rows as $r) $map[(int) $r['jam_level']] = (int) $r['total'];

        $out = [];
        for ($lvl = 0; $lvl <= 5; $lvl++) {
            $out[] = ['level' => $lvl, 'count' => $map[$lvl] ?? 0];
        }
        return $out;
    }

    /**
     * Sub-rotas ordenadas por ratio desc (mais problemáticas primeiro).
     *
     * O `name` do banco pode vir nulo — quando isso acontece, monta
     * `from → to` a partir dos nomes de vias, que são mais úteis que "Trecho".
     *
     * @return list<array<string,mixed>>
     */
    private function loadTopSubRoutes(?Partner $partner, int $routeId): array
    {
        $params = ['id' => $routeId];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND sr.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT
                    sr.id, sr.name, sr.from_name, sr.to_name,
                    sr.time, sr.historic_time, sr.jam_level, sr.length
                FROM waze_tvt_sub_route sr
                WHERE sr.route_id = :id
                  AND sr.is_active = 1
                  {$partnerFilter}
                ORDER BY
                    CASE WHEN sr.historic_time > 0
                         THEN (sr.time - sr.historic_time) / sr.historic_time
                         ELSE -1
                    END DESC,
                    sr.id ASC
                LIMIT 15";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(function ($r) {
            $time     = $r['time']          !== null ? (float) $r['time']          : null;
            $historic = $r['historic_time'] !== null ? (float) $r['historic_time'] : null;

            $delay = ($time !== null && $historic !== null)
                ? max(0, (int) round($time - $historic))
                : null;

            $ratio = ($delay !== null && $historic !== null && $historic > 0)
                ? $delay / $historic
                : null;

            // ── Nome de exibição ─────────────────────────────────
            // Prefere name do banco; se vazio, monta com from/to.
            $displayName = trim((string) ($r['name'] ?? ''));
            if ($displayName === '' || mb_strtolower($displayName) === 'trecho') {
                $from = trim((string) ($r['from_name'] ?? ''));
                $to   = trim((string) ($r['to_name'] ?? ''));
                if ($from !== '' && $to !== '') {
                    $displayName = $from . ' → ' . $to;
                } elseif ($from !== '') {
                    $displayName = $from;
                } elseif ($to !== '') {
                    $displayName = $to;
                } else {
                    $displayName = 'Trecho sem nome';
                }
            }

            return [
                'id'           => (int) $r['id'],
                'name'         => $displayName,
                'from'         => $r['from_name'],
                'to'           => $r['to_name'],
                'time'         => $time,
                'historicTime' => $historic,
                'delaySeconds' => $delay,
                'delayRatio'   => $ratio,
                'jamLevel'     => $r['jam_level'] !== null ? (int) $r['jam_level'] : null,
                'lengthMeters' => $r['length'] !== null ? (int) $r['length'] : null,
            ];
        }, $rows);
    }

    /**
     * Polyline das sub-rotas ativas — usada pelos mini-mapas.
     * Retorna `[ { id, line } ]` pra cruzar com `topSubRoutes`.
     *
     * @return array<int, list<array{0:float,1:float}>>
     */
    private function loadSubRoutesPolyline(?Partner $partner, int $routeId): array
    {
        $params = ['id' => $routeId];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $rows = $this->connection->executeQuery(
            "SELECT id, line FROM waze_tvt_sub_route
             WHERE route_id = :id
               AND is_active = 1
               AND line IS NOT NULL
               {$partnerFilter}",
            $params
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $line = $this->normalizeLine($row['line']);
            if ($line !== []) {
                $out[(int) $row['id']] = $line;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function loadRecentIrregularities(?Partner $partner, int $routeId): array
    {
        $params = ['id' => $routeId];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT id, type, subtype, severity, description, street, city, latitude, longitude, reported_time
                FROM waze_tvt_irregularity
                WHERE route_id = :id
                  AND is_active = 1
                  {$partnerFilter}
                ORDER BY reported_time DESC
                LIMIT 20";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'          => (int) $r['id'],
            'type'        => $r['type'],
            'subtype'     => $r['subtype'],
            'severity'    => $r['severity'],
            'description' => $r['description'],
            'street'      => $r['street'],
            'city'        => $r['city'],
            'lat'         => $r['latitude']  !== null ? (float) $r['latitude']  : null,
            'lng'         => $r['longitude'] !== null ? (float) $r['longitude'] : null,
            'reportedAt'  => $r['reported_time'],
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Stats agregadas
    // ─────────────────────────────────────────────────────────────────────

    private function computeStats(array $timeline, array $heatmap, array $byHour, array $byDow, ?array $current): array
    {
        $avgRatio24h = null;
        $avgDelay24h = null;
        $avgRatio7d  = null;
        $avgDelay7d  = null;

        if ($timeline !== []) {
            $sumR = 0.0; $sumD = 0.0; $n = 0;
            foreach ($timeline as $p) {
                if ($p['avgRatio'] !== null) { $sumR += $p['avgRatio']; $n++; }
                if ($p['avgDelay'] !== null) { $sumD += $p['avgDelay']; }
            }
            if ($n > 0) {
                $avgRatio7d = $sumR / $n;
                $avgDelay7d = $sumD / $n;
            }

            $cutoff = time() - 86400;
            $sumR = 0.0; $sumD = 0.0; $n = 0;
            foreach ($timeline as $p) {
                $ts = strtotime((string) $p['time'] . ' UTC');
                if ($ts === false || $ts < $cutoff) continue;
                if ($p['avgRatio'] !== null) { $sumR += $p['avgRatio']; $n++; }
                if ($p['avgDelay'] !== null) { $sumD += $p['avgDelay']; }
            }
            if ($n > 0) {
                $avgRatio24h = $sumR / $n;
                $avgDelay24h = $sumD / $n;
            }
        }

        $worstHour = null;
        foreach ($byHour as $p) {
            if ($p['avgRatio'] === null || $p['count'] < 2) continue;
            if ($worstHour === null || $p['avgRatio'] > $worstHour['avgRatio']) {
                $worstHour = $p;
            }
        }

        $worstDow = null;
        foreach ($byDow as $p) {
            if ($p['avgRatio'] === null || $p['count'] < 2) continue;
            if ($worstDow === null || $p['avgRatio'] > $worstDow['avgRatio']) {
                $worstDow = $p;
            }
        }

        $bestDow = null;
        foreach ($byDow as $p) {
            if ($p['avgRatio'] === null || $p['count'] < 2) continue;
            if ($bestDow === null || $p['avgRatio'] < $bestDow['avgRatio']) {
                $bestDow = $p;
            }
        }

        // Health score — baseado em RATIO, não em delay absoluto
        $health = 100;
        if ($avgRatio7d !== null) {
            $health -= min(70, (int) round($avgRatio7d * 100));
        }
        if ($current && $current['delayRatio'] !== null) {
            $health -= min(20, (int) round($current['delayRatio'] * 40));
        }
        $health = max(0, min(100, $health));

        return [
            'avgDelay24h'    => $avgDelay24h !== null ? round($avgDelay24h, 1) : null,
            'avgRatio24h'    => $avgRatio24h,
            'avgDelay7d'     => $avgDelay7d !== null ? round($avgDelay7d, 1) : null,
            'avgRatio7d'     => $avgRatio7d,
            'worstHour'      => $worstHour,
            'worstDow'       => $worstDow,
            'bestDow'        => $bestDow,
            'sampleCount7d'  => count($timeline),
            'sampleCount30d' => count($heatmap),
            'health'         => $health,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cross-reference espacial: alertas próximos ao traçado
    // ─────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function loadNearbyAlerts(
        ?Partner $partner,
        int $routeId,
        int $days = 7,
        float $bufferMeters = 60.0,
    ): array {
        $polyline = $this->loadRoutePolyline($partner, $routeId);
        if ($polyline === []) {
            return [];
        }

        $minLat = $maxLat = $polyline[0][0];
        $minLng = $maxLng = $polyline[0][1];
        foreach ($polyline as $p) {
            if ($p[0] < $minLat) $minLat = $p[0];
            if ($p[0] > $maxLat) $maxLat = $p[0];
            if ($p[1] < $minLng) $minLng = $p[1];
            if ($p[1] > $maxLng) $maxLng = $p[1];
        }
        $pad = 0.002;
        $minLat -= $pad; $maxLat += $pad;
        $minLng -= $pad; $maxLng += $pad;

        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $params = [
            'since'  => $since,
            'minLat' => $minLat,
            'maxLat' => $maxLat,
            'minLng' => $minLng,
            'maxLng' => $maxLng,
        ];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND a.partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $sql = "SELECT
                    a.id, a.type, a.subtype, a.street, a.city,
                    a.confidence, a.reliability,
                    CAST(a.latitude  AS DECIMAL(10,7)) AS lat,
                    CAST(a.longitude AS DECIMAL(10,7)) AS lng,
                    a.pub_millis, a.collected_at
                FROM waze_alerts a
                WHERE a.collected_at >= :since
                  AND a.latitude  BETWEEN :minLat AND :maxLat
                  AND a.longitude BETWEEN :minLng AND :maxLng
                  {$partnerFilter}
                ORDER BY a.collected_at DESC
                LIMIT 300";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $lat = (float) $r['lat'];
            $lng = (float) $r['lng'];

            $dist = $this->distanceToPolyline($lat, $lng, $polyline);
            if ($dist > $bufferMeters) continue;

            $out[] = [
                'id'             => (int) $r['id'],
                'type'           => $r['type'],
                'subtype'        => $r['subtype'],
                'street'         => $r['street'],
                'city'           => $r['city'],
                'confidence'     => (int) $r['confidence'],
                'reliability'    => (int) $r['reliability'],
                'lat'            => $lat,
                'lng'            => $lng,
                'distanceMeters' => (int) round($dist),
                'pubDateTime'    => $r['pub_millis']
                    ? (new \DateTimeImmutable('@' . intdiv((int) $r['pub_millis'], 1000)))->format(DATE_ATOM)
                    : null,
                'collectedAt'    => $r['collected_at'],
                'typeLabel'      => $this->labelAlertType((string) $r['type']),
            ];
        }

        return $out;
    }

    /**
     * Polyline [lat,lng] da rota: geometry principal + linhas das sub-rotas.
     *
     * @return list<array{0:float,1:float}>
     */
    private function loadRoutePolyline(?Partner $partner, int $routeId): array
    {
        $params = ['id' => $routeId];
        $partnerFilter = '';
        if ($partner !== null) {
            $partnerFilter        = ' AND partner_id = :pid';
            $params['pid']        = $partner->getId();
        }

        $geom = $this->connection->executeQuery(
            "SELECT geometry FROM waze_tvt_route WHERE id = :id {$partnerFilter}",
            $params
        )->fetchOne();

        $polyline = $this->normalizeLine($geom);

        $subRows = $this->connection->executeQuery(
            "SELECT line FROM waze_tvt_sub_route
             WHERE route_id = :id
               AND is_active = 1
               AND line IS NOT NULL
               {$partnerFilter}",
            $params
        )->fetchAllAssociative();

        foreach ($subRows as $row) {
            $line = $this->normalizeLine($row['line']);
            foreach ($line as $pt) {
                $polyline[] = $pt;
            }
        }

        return $polyline;
    }

    private function distanceToPolyline(float $lat, float $lng, array $polyline): float
    {
        $n = count($polyline);
        if ($n === 0) return PHP_FLOAT_MAX;
        if ($n === 1) {
            return $this->pointToSegmentDistance(
                $lat, $lng,
                $polyline[0][0], $polyline[0][1],
                $polyline[0][0], $polyline[0][1],
            );
        }

        $min = INF;
        for ($i = 0; $i < $n - 1; $i++) {
            $d = $this->pointToSegmentDistance(
                $lat, $lng,
                $polyline[$i][0],     $polyline[$i][1],
                $polyline[$i + 1][0], $polyline[$i + 1][1],
            );
            if ($d < $min) $min = $d;
        }
        return $min;
    }

    private function pointToSegmentDistance(
        float $lat, float $lng,
        float $latA, float $lngA,
        float $latB, float $lngB,
    ): float {
        $cosLat = cos(deg2rad($lat));

        $px = $lng  * $cosLat;  $py = $lat;
        $ax = $lngA * $cosLat;  $ay = $latA;
        $bx = $lngB * $cosLat;  $by = $latB;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lenSq = $dx * $dx + $dy * $dy;

        if ($lenSq <= 0.0) {
            $t = 0.0;
        } else {
            $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $lenSq;
            $t = max(0.0, min(1.0, $t));
        }

        $cx = $ax + $t * $dx;
        $cy = $ay + $t * $dy;

        $ddx = $px - $cx;
        $ddy = $py - $cy;

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
                if (is_array($c) && count($c) >= 2) {
                    $out[] = [(float) $c[1], (float) $c[0]];
                }
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
