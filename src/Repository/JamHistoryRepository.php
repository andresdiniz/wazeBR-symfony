<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;

/**
 * Consultas HISTÓRICAS de congestionamentos (/jams/historico).
 *
 *  • Agregações por hora, dia-da-semana, nível, status
 *  • Rankings por rua / cidade
 *  • Timeline adaptativa (hora / dia / semana)
 *  • Correlação espacial+temporal com waze_alerts
 *  • Comparação com período anterior (deltas nos KPIs)
 *  • Export CSV com colunas ricas + correlação
 *
 *  Regras de negócio (idênticas ao JamRepository em tempo real):
 *    Interdição: level = 5 OR delay = -1
 *    Antiga:     interdição + last_seen_at < NOW() - STALE_MINUTES
 */
final class JamHistoryRepository
{
    private const STALE_MINUTES      = 15;
    private const NEARBY_WINDOW_MIN  = 30;
    private const NEARBY_RADIUS_M    = 300;
    private const GEO_CELL_DEG       = 0.009;   // ≈ 1 km
    private const MAX_RANGE_DAYS     = 90;
    private const TABLE_PAGE_SIZE    = 40;
    private const EXPORT_LIMIT       = 20_000;
    private const CORRELATION_SAMPLE = 400;
    private const TIMEZONE           = 'America/Sao_Paulo';

    public function __construct(private readonly Connection $connection)
    {
    }

    // ═══════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string,mixed> */
    public function getHistoricalDashboard(?Partner $partner, array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        return [
            'summary'          => $this->loadSummaryWithDeltas($partner, $filters),
            'byHour'           => $this->loadByHour($partner, $filters),
            'byDayOfWeek'      => $this->loadByDayOfWeek($partner, $filters),
            'byLevel'          => $this->loadByLevel($partner, $filters),
            'byStatus'         => $this->loadByStatus($partner, $filters),
            'timeline'         => $this->loadTimeline($partner, $filters),
            'topStreets'       => $this->loadTopStreets($partner, $filters),
            'topCities'        => $this->loadTopCities($partner, $filters),
            'alertCorrelation' => $this->loadAlertCorrelation($partner, $filters),
            'recentJams'       => $this->loadRecentJams($partner, $filters, self::TABLE_PAGE_SIZE, 0),
            'period'           => [
                'from'   => $filters['date_from'],
                'to'     => $filters['date_to'],
                'days'   => $filters['days'],
                'bucket' => $filters['bucket'],
            ],
            'generatedAt'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /** @return array<string,int> */
    public function fetchDistinctCities(?Partner $partner, int $limit = 40): array
    {
        $pf     = '';
        $params = [];

        if ($partner !== null) {
            $pf             = ' AND partner_id = :pid';
            $params['pid']  = $partner->getId();
        }

        try {
            $rows = $this->connection->executeQuery(
                "SELECT city, COUNT(*) AS n
                 FROM waze_jams
                 WHERE city IS NOT NULL AND city <> ''
                   AND collected_at >= UTC_TIMESTAMP() - INTERVAL 90 DAY
                   {$pf}
                 GROUP BY city
                 ORDER BY n DESC
                 LIMIT " . (int) $limit,
                $params
            )->fetchAllAssociative();

            $out = [];
            foreach ($rows as $r) {
                $out[(string) $r['city']] = (int) $r['n'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function loadExportRows(?Partner $partner, array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);

        return $this->connection->executeQuery(
            "SELECT
                j.id, j.uuid, j.street, j.city, j.country,
                j.level, j.delay, j.length, j.speed_kmh, j.line_points,
                j.collected_at, j.last_seen_at, j.deactivated_at,
                j.is_active,
                " . $this->sqlBlocked('j') . " AS is_blocked,
                " . $this->sqlStale('j')   . " AS is_stale,
                TIMESTAMPDIFF(MINUTE, j.collected_at, j.last_seen_at) AS duration_min
             FROM waze_jams j
             {$where}
             ORDER BY j.collected_at DESC
             LIMIT " . self::EXPORT_LIMIT,
            $params
        )->fetchAllAssociative();
    }

    // ═══════════════════════════════════════════════════════════════════
    // SUMMARY + DELTAS
    // ═══════════════════════════════════════════════════════════════════

    private function loadSummaryWithDeltas(?Partner $partner, array $filters): array
    {
        $current = $this->loadSummaryForRange($partner, $filters);

        $tz     = new \DateTimeZone(self::TIMEZONE);
        $from   = new \DateTimeImmutable($filters['date_from'], $tz);
        $to     = new \DateTimeImmutable($filters['date_to'],   $tz);
        $length = (int) $from->diff($to)->days + 1;

        $prevTo   = $from->modify('-1 day');
        $prevFrom = $prevTo->modify('-' . ($length - 1) . ' days');

        $prevFilters               = $filters;
        $prevFilters['date_from']  = $prevFrom->format('Y-m-d');
        $prevFilters['date_to']    = $prevTo->format('Y-m-d');

        $previous = $this->loadSummaryForRange($partner, $prevFilters);

        $prevRange = [
            'from' => $prevFilters['date_from'],
            'to'   => $prevFilters['date_to'],
        ];

        $delta = static function (float|int $cur, float|int $prev): ?float {
            if ($prev == 0) {
                return $cur == 0 ? 0.0 : null;
            }
            return round((($cur - $prev) / $prev) * 100, 1);
        };

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => [
                'total'         => $delta($current['total'],         $previous['total']),
                'blocked'       => $delta($current['blocked'],       $previous['blocked']),
                'avgDelay'      => $delta($current['avgDelay'],      $previous['avgDelay']),
                'avgDuration'   => $delta($current['avgDuration'],   $previous['avgDuration']),
                'totalLength'   => $delta($current['totalLength'],   $previous['totalLength']),
            ],
            'prevRange' => $prevRange,
        ];
    }

    private function loadSummaryForRange(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = $this->sqlBlocked('j');
        $stale   = $this->sqlStale('j');

        $row = $this->connection->executeQuery(
            "SELECT
                COUNT(*)                                                              AS total,
                SUM(j.level = 1)                                                      AS lvl1,
                SUM(j.level = 2)                                                      AS lvl2,
                SUM(j.level = 3)                                                      AS lvl3,
                SUM(j.level = 4)                                                      AS lvl4,
                SUM(j.level = 5)                                                      AS lvl5,
                SUM({$blocked})                                                       AS blocked,
                SUM({$blocked} AND NOT {$stale})                                      AS blocked_active,
                SUM({$blocked} AND {$stale})                                          AS blocked_stale,
                COALESCE(AVG(CASE WHEN j.delay >= 0 THEN j.delay END), 0)             AS avg_delay,
                COALESCE(MAX(CASE WHEN j.delay >= 0 THEN j.delay END), 0)             AS max_delay,
                COALESCE(AVG(j.speed_kmh), 0)                                         AS avg_speed,
                COALESCE(SUM(j.length), 0)                                            AS total_length,
                COALESCE(AVG(TIMESTAMPDIFF(MINUTE, j.collected_at, j.last_seen_at)),0) AS avg_duration,
                MAX(j.collected_at)                                                   AS last_seen,
                MIN(j.collected_at)                                                   AS first_seen,
                COUNT(DISTINCT j.city)                                                AS distinct_cities,
                COUNT(DISTINCT j.street)                                              AS distinct_streets
             FROM waze_jams j
             {$where}",
            $params
        )->fetchAssociative() ?: [];

        return [
            'total'          => (int)   ($row['total']           ?? 0),
            'byLevel'        => [
                1 => (int) ($row['lvl1'] ?? 0),
                2 => (int) ($row['lvl2'] ?? 0),
                3 => (int) ($row['lvl3'] ?? 0),
                4 => (int) ($row['lvl4'] ?? 0),
                5 => (int) ($row['lvl5'] ?? 0),
            ],
            'blocked'        => (int)   ($row['blocked']         ?? 0),
            'blockedActive'  => (int)   ($row['blocked_active']  ?? 0),
            'blockedStale'   => (int)   ($row['blocked_stale']   ?? 0),
            'avgDelay'       => (int) round((float) ($row['avg_delay']    ?? 0)),
            'maxDelay'       => (int)   ($row['max_delay']       ?? 0),
            'avgSpeed'       => (int) round((float) ($row['avg_speed']    ?? 0)),
            'avgDuration'    => (int) round((float) ($row['avg_duration'] ?? 0)),
            'totalLength'    => (int)   ($row['total_length']    ?? 0),
            'distinctCities' => (int)   ($row['distinct_cities'] ?? 0),
            'distinctStreets'=> (int)   ($row['distinct_streets']?? 0),
            'lastSeen'       => $this->toIso($row['last_seen']   ?? null),
            'firstSeen'      => $this->toIso($row['first_seen']  ?? null),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // AGRUPAMENTOS
    // ═══════════════════════════════════════════════════════════════════

    private function loadByHour(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);

        // Converte para hora local antes de agrupar (bucket 0-23)
        $tzShift = $this->tzShiftSql('j.collected_at');

        $rows = $this->connection->executeQuery(
            "SELECT
                HOUR({$tzShift}) AS hour_local,
                COUNT(*)         AS cnt,
                AVG(CASE WHEN j.delay >= 0 THEN j.delay END) AS avg_delay,
                SUM(" . $this->sqlBlocked('j') . ") AS blocked
             FROM waze_jams j
             {$where}
             GROUP BY hour_local
             ORDER BY hour_local ASC",
            $params
        )->fetchAllAssociative();

        $lookup = [];
        foreach ($rows as $r) {
            $lookup[(int) $r['hour_local']] = [
                'count'    => (int) $r['cnt'],
                'avgDelay' => (int) round((float) ($r['avg_delay'] ?? 0)),
                'blocked'  => (int) $r['blocked'],
            ];
        }

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = [
                'hour'     => $h,
                'label'    => sprintf('%02dh', $h),
                'count'    => $lookup[$h]['count']    ?? 0,
                'avgDelay' => $lookup[$h]['avgDelay'] ?? 0,
                'blocked'  => $lookup[$h]['blocked']  ?? 0,
            ];
        }
        return $out;
    }

    private function loadByDayOfWeek(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $tzShift = $this->tzShiftSql('j.collected_at');

        // MySQL DAYOFWEEK: 1=Dom..7=Sáb
        $rows = $this->connection->executeQuery(
            "SELECT
                DAYOFWEEK({$tzShift}) AS dow,
                COUNT(*) AS cnt,
                AVG(CASE WHEN j.delay >= 0 THEN j.delay END) AS avg_delay,
                SUM(" . $this->sqlBlocked('j') . ") AS blocked
             FROM waze_jams j
             {$where}
             GROUP BY dow
             ORDER BY dow ASC",
            $params
        )->fetchAllAssociative();

        $lookup = [];
        foreach ($rows as $r) {
            $lookup[(int) $r['dow']] = [
                'count'    => (int) $r['cnt'],
                'avgDelay' => (int) round((float) ($r['avg_delay'] ?? 0)),
                'blocked'  => (int) $r['blocked'],
            ];
        }

        $labels = [1 => 'Dom', 2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb'];
        $out    = [];
        for ($d = 1; $d <= 7; $d++) {
            $out[] = [
                'dow'      => $d,
                'label'    => $labels[$d],
                'count'    => $lookup[$d]['count']    ?? 0,
                'avgDelay' => $lookup[$d]['avgDelay'] ?? 0,
                'blocked'  => $lookup[$d]['blocked']  ?? 0,
            ];
        }
        return $out;
    }

    private function loadByLevel(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);

        $rows = $this->connection->executeQuery(
            "SELECT j.level, COUNT(*) AS cnt
             FROM waze_jams j
             {$where}
             GROUP BY j.level
             ORDER BY j.level ASC",
            $params
        )->fetchAllAssociative();

        $lookup = [];
        foreach ($rows as $r) {
            $lookup[(int) $r['level']] = (int) $r['cnt'];
        }

        $labels = [1 => 'Baixo', 2 => 'Moderado', 3 => 'Alto', 4 => 'Muito alto', 5 => 'Parado'];
        $out    = [];
        foreach ([1, 2, 3, 4, 5] as $lvl) {
            $out[] = [
                'level' => $lvl,
                'label' => $labels[$lvl],
                'count' => $lookup[$lvl] ?? 0,
            ];
        }
        return $out;
    }

    private function loadByStatus(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = $this->sqlBlocked('j');
        $stale   = $this->sqlStale('j');

        $row = $this->connection->executeQuery(
            "SELECT
                SUM(NOT {$blocked})                       AS normal,
                SUM({$blocked} AND NOT {$stale})          AS active,
                SUM({$blocked} AND {$stale})              AS stale
             FROM waze_jams j
             {$where}",
            $params
        )->fetchAssociative() ?: [];

        return [
            ['status' => 'normal', 'label' => 'Congestionamento',    'count' => (int) ($row['normal'] ?? 0)],
            ['status' => 'active', 'label' => 'Interdição ativa',    'count' => (int) ($row['active'] ?? 0)],
            ['status' => 'stale',  'label' => 'Interdição antiga',   'count' => (int) ($row['stale']  ?? 0)],
        ];
    }

    private function loadTimeline(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);

        $fmt = match ($filters['bucket']) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day'  => '%Y-%m-%d 00:00:00',
            default => null, // week
        };

        if ($fmt !== null) {
            $expr = "DATE_FORMAT(" . $this->tzShiftSql('j.collected_at') . ", '{$fmt}')";
        } else {
            // week bucket (segunda-feira como início)
            $expr = "DATE(DATE_SUB(" . $this->tzShiftSql('j.collected_at')
                  . ", INTERVAL WEEKDAY(" . $this->tzShiftSql('j.collected_at') . ") DAY))";
        }

        $rows = $this->connection->executeQuery(
            "SELECT
                {$expr} AS bucket,
                COUNT(*)         AS jam_count,
                SUM(" . $this->sqlBlocked('j') . ") AS blocked_count,
                AVG(CASE WHEN j.delay >= 0 THEN j.delay END) AS avg_delay
             FROM waze_jams j
             {$where}
             GROUP BY bucket
             ORDER BY bucket ASC",
            $params
        )->fetchAllAssociative();

        $jamMap = [];
        foreach ($rows as $r) {
            $jamMap[(string) $r['bucket']] = [
                'jams'    => (int) $r['jam_count'],
                'blocked' => (int) $r['blocked_count'],
                'avgDelay'=> (int) round((float) ($r['avg_delay'] ?? 0)),
            ];
        }

        // Contagem de alertas no mesmo bucket (para o overlay de correlação)
        $alertRows = $this->loadAlertCountsByBucket($partner, $filters, $expr);
        $alertMap  = [];
        foreach ($alertRows as $r) {
            $alertMap[(string) $r['bucket']] = (int) $r['alert_count'];
        }

        // Preenche buckets vazios entre from/to
        $buckets = $this->enumerateBuckets($filters);

        $out = [];
        foreach ($buckets as $bucket) {
            $out[] = [
                'at'        => $bucket,
                'jams'      => $jamMap[$bucket]['jams']     ?? 0,
                'blocked'   => $jamMap[$bucket]['blocked']  ?? 0,
                'avgDelay'  => $jamMap[$bucket]['avgDelay'] ?? 0,
                'alerts'    => $alertMap[$bucket]           ?? 0,
            ];
        }
        return $out;
    }

    private function loadAlertCountsByBucket(?Partner $partner, array $filters, string $expr): array
    {
        // Reutiliza a MESMA expressão de bucket — trocando o alias da tabela
        $alertExpr = str_replace('j.collected_at', 'a.collected_at', $expr);

        $pf     = '';
        $params = [];
        if ($partner !== null) {
            $pf             = ' AND a.partner_id = :pid';
            $params['pid']  = $partner->getId();
        }

        $tz = new \DateTimeZone(self::TIMEZONE);
        $fromUtc = (new \DateTimeImmutable($filters['date_from'] . ' 00:00:00', $tz))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $toUtc = (new \DateTimeImmutable($filters['date_to'] . ' 23:59:59', $tz))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        try {
            return $this->connection->executeQuery(
                "SELECT {$alertExpr} AS bucket, COUNT(*) AS alert_count
                 FROM waze_alerts a
                 WHERE a.collected_at BETWEEN :fromUtc AND :toUtc
                   {$pf}
                 GROUP BY bucket
                 ORDER BY bucket ASC",
                $params + ['fromUtc' => $fromUtc, 'toUtc' => $toUtc]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return string[] Lista ordenada de buckets no formato SQL do agrupamento */
    private function enumerateBuckets(array $filters): array
    {
        $tz   = new \DateTimeZone(self::TIMEZONE);
        $from = new \DateTimeImmutable($filters['date_from'] . ' 00:00:00', $tz);
        $to   = new \DateTimeImmutable($filters['date_to']   . ' 23:59:59', $tz);

        $out    = [];
        $cursor = $from;

        $step = match ($filters['bucket']) {
            'hour' => '+1 hour',
            'day'  => '+1 day',
            default => '+7 days',
        };

        if ($filters['bucket'] === 'week') {
            // Alinha para segunda-feira
            $cursor = $cursor->modify('monday this week');
            if ($cursor < $from) {
                $cursor = $cursor->modify('+7 days');
            }
        }

        while ($cursor <= $to) {
            $out[]  = match ($filters['bucket']) {
                'hour' => $cursor->format('Y-m-d H:00:00'),
                'day'  => $cursor->format('Y-m-d 00:00:00'),
                default=> $cursor->format('Y-m-d 00:00:00'),
            };
            $cursor = $cursor->modify($step);
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════
    // RANKINGS
    // ═══════════════════════════════════════════════════════════════════

    private function loadTopStreets(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = $this->sqlBlocked('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.street, j.city,
                COUNT(*)                                     AS cnt,
                SUM({$blocked})                              AS blocked,
                MAX(j.level)                                 AS max_level,
                AVG(CASE WHEN j.delay >= 0 THEN j.delay END) AS avg_delay,
                MAX(CASE WHEN j.delay >= 0 THEN j.delay END) AS max_delay
             FROM waze_jams j
             {$where}
               AND j.street IS NOT NULL
               AND j.street <> ''
             GROUP BY j.street, j.city
             ORDER BY cnt DESC, blocked DESC
             LIMIT 12",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'street'   => (string) $r['street'],
            'city'     => (string) ($r['city'] ?? ''),
            'count'    => (int) $r['cnt'],
            'blocked'  => (int) $r['blocked'],
            'maxLevel' => (int) $r['max_level'],
            'avgDelay' => (int) round((float) ($r['avg_delay'] ?? 0)),
            'maxDelay' => (int) ($r['max_delay'] ?? 0),
        ], $rows);
    }

    private function loadTopCities(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = $this->sqlBlocked('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.city,
                COUNT(*)                                     AS cnt,
                SUM({$blocked})                              AS blocked,
                MAX(j.level)                                 AS max_level,
                AVG(CASE WHEN j.delay >= 0 THEN j.delay END) AS avg_delay
             FROM waze_jams j
             {$where}
               AND j.city IS NOT NULL
               AND j.city <> ''
             GROUP BY j.city
             ORDER BY cnt DESC, blocked DESC
             LIMIT 12",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'city'     => (string) $r['city'],
            'count'    => (int) $r['cnt'],
            'blocked'  => (int) $r['blocked'],
            'maxLevel' => (int) $r['max_level'],
            'avgDelay' => (int) round((float) ($r['avg_delay'] ?? 0)),
        ], $rows);
    }

    // ═══════════════════════════════════════════════════════════════════
    // TABELA — recentes + correlação alerta
    // ═══════════════════════════════════════════════════════════════════

    private function loadRecentJams(?Partner $partner, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = $this->sqlBlocked('j');
        $stale   = $this->sqlStale('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.id, j.uuid, j.street, j.city, j.country,
                j.level, j.delay, j.length, j.speed_kmh, j.line_points,
                j.line, j.collected_at, j.last_seen_at, j.is_active,
                ({$blocked}) AS is_blocked,
                ({$stale})   AS is_stale,
                TIMESTAMPDIFF(MINUTE, j.collected_at, j.last_seen_at) AS duration_min
             FROM waze_jams j
             {$where}
             ORDER BY j.collected_at DESC
             LIMIT " . (int) $limit . " OFFSET " . (int) $offset,
            $params
        )->fetchAllAssociative();

        $mapped = array_map([$this, 'mapRow'], $rows);

        return $this->attachNearbyAlerts($mapped);
    }

    private function mapRow(array $r): array
    {
        $delay = (int) $r['delay'];

        $path = [];
        $line = $r['line'] ?? null;
        if (is_string($line)) {
            $decoded = json_decode($line, true);
            $line    = is_array($decoded) ? $decoded : [];
        }
        foreach ((is_array($line) ? $line : []) as $pt) {
            if (is_array($pt) && isset($pt['x'], $pt['y'])) {
                $path[] = [(float) $pt['y'], (float) $pt['x']];
            } elseif (is_array($pt) && count($pt) >= 2) {
                $path[] = [(float) $pt[1], (float) $pt[0]];
            }
        }

        return [
            'id'          => (int) $r['id'],
            'uuid'        => $r['uuid'],
            'street'      => $r['street'],
            'city'        => $r['city'],
            'country'     => $r['country'],
            'level'       => (int) $r['level'],
            'delay'       => $delay,
            'delayMin'    => $delay >= 0 ? round($delay / 60, 1) : null,
            'length'      => (int) $r['length'],
            'lengthKm'    => round(((int) $r['length']) / 1000, 2),
            'speed'       => (float) $r['speed_kmh'],
            'points'      => (int) $r['line_points'],
            'isBlocked'   => (bool) ($r['is_blocked'] ?? false),
            'isStale'     => (bool) ($r['is_stale']   ?? false),
            'isActive'    => (bool) ($r['is_active']  ?? true),
            'when'        => $this->toIso($r['collected_at']),
            'lastSeen'    => $this->toIso($r['last_seen_at']),
            'durationMin' => (int) ($r['duration_min'] ?? 0),
            'path'        => $path,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // CORRELAÇÃO COM ALERTAS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Enriquece jams com `nearbyAlerts` (int) e `alerts` (top 3 próximos).
     * Usa grid geográfico — mesma abordagem do JamRepository.
     */
    private function attachNearbyAlerts(array $jams): array
    {
        if ($jams === []) {
            return $jams;
        }

        $byJam = $this->findNearbyAlertsForJams($jams);

        return array_map(static function (array $j) use ($byJam) {
            $alerts            = $byJam[(int) $j['id']] ?? [];
            $j['nearbyAlerts'] = count($alerts);
            $j['alerts']       = array_slice($alerts, 0, 3);
            return $j;
        }, $jams);
    }

    private function findNearbyAlertsForJams(array $jams): array
    {
        $minLat = $minLng = PHP_FLOAT_MAX;
        $maxLat = $maxLng = -PHP_FLOAT_MAX;
        $minTs  = PHP_INT_MAX;
        $maxTs  = PHP_INT_MIN;

        foreach ($jams as $j) {
            foreach ($j['path'] ?? [] as $pt) {
                if (!is_array($pt) || count($pt) < 2) continue;
                $minLat = min($minLat, (float) $pt[0]);
                $maxLat = max($maxLat, (float) $pt[0]);
                $minLng = min($minLng, (float) $pt[1]);
                $maxLng = max($maxLng, (float) $pt[1]);
            }
            $ts = strtotime((string) ($j['when'] ?? ''));
            if ($ts !== false) {
                $minTs = min($minTs, $ts);
                $maxTs = max($maxTs, $ts);
            }
        }

        if ($minTs === PHP_INT_MAX) {
            return array_fill_keys(array_column($jams, 'id'), []);
        }

        $pad    = self::NEARBY_RADIUS_M / 111_000;
        $minLat -= $pad; $maxLat += $pad;
        $minLng -= $pad; $maxLng += $pad;

        $win = self::NEARBY_WINDOW_MIN * 60;
        $from = gmdate('Y-m-d H:i:s', $minTs - $win);
        $to   = gmdate('Y-m-d H:i:s', $maxTs + $win);

        $rows = $this->connection->executeQuery(
            "SELECT id, uuid, type, subtype, street, city,
                    latitude, longitude, confidence, collected_at
             FROM waze_alerts
             WHERE is_active = 1
               AND latitude  BETWEEN :minLat AND :maxLat
               AND longitude BETWEEN :minLng AND :maxLng
               AND collected_at BETWEEN :tsFrom AND :tsTo
             ORDER BY collected_at DESC
             LIMIT 5000",
            [
                'minLat' => $minLat, 'maxLat' => $maxLat,
                'minLng' => $minLng, 'maxLng' => $maxLng,
                'tsFrom' => $from,   'tsTo'   => $to,
            ]
        )->fetchAllAssociative();

        $cell   = self::GEO_CELL_DEG;
        $grid   = [];
        $radius = self::NEARBY_RADIUS_M * self::NEARBY_RADIUS_M;

        foreach ($rows as $r) {
            $lat = (float) $r['latitude'];
            $lng = (float) $r['longitude'];
            if ($lat === 0.0 && $lng === 0.0) continue;

            $ts = strtotime((string) $r['collected_at']);
            if ($ts === false) continue;

            $a = [
                'id'         => (int) $r['id'],
                'uuid'       => $r['uuid'],
                'type'       => $r['type'],
                'subtype'    => $r['subtype'],
                'street'     => $r['street'],
                'city'       => $r['city'],
                'lat'        => $lat,
                'lng'        => $lng,
                'confidence' => (int) $r['confidence'],
                '_ts'        => $ts,
            ];
            $cx = (int) floor($lat / $cell);
            $cy = (int) floor($lng / $cell);
            $grid["{$cx},{$cy}"][] = $a;
        }

        $out = [];

        foreach ($jams as $j) {
            $jamId = (int) $j['id'];
            $out[$jamId] = [];

            $jamTs = strtotime((string) ($j['when'] ?? ''));
            $path  = $j['path'] ?? [];
            if ($jamTs === false || $path === []) continue;

            $jMinLat = $jMinLng = PHP_FLOAT_MAX;
            $jMaxLat = $jMaxLng = -PHP_FLOAT_MAX;
            foreach ($path as $pt) {
                $jMinLat = min($jMinLat, (float) $pt[0]);
                $jMaxLat = max($jMaxLat, (float) $pt[0]);
                $jMinLng = min($jMinLng, (float) $pt[1]);
                $jMaxLng = max($jMaxLng, (float) $pt[1]);
            }

            $cxMin = (int) floor(($jMinLat - $pad) / $cell);
            $cxMax = (int) floor(($jMaxLat + $pad) / $cell);
            $cyMin = (int) floor(($jMinLng - $pad) / $cell);
            $cyMax = (int) floor(($jMaxLng + $pad) / $cell);

            $seen = [];
            for ($cx = $cxMin; $cx <= $cxMax; $cx++) {
                for ($cy = $cyMin; $cy <= $cyMax; $cy++) {
                    foreach ($grid["{$cx},{$cy}"] ?? [] as $a) {
                        if (isset($seen[$a['id']])) continue;
                        $seen[$a['id']] = true;

                        if (abs($a['_ts'] - $jamTs) > $win) continue;

                        $dSq = $this->minDistanceSqToPolyline($a['lat'], $a['lng'], $path);
                        if ($dSq > $radius) continue;

                        $aOut = $a;
                        unset($aOut['_ts']);
                        $aOut['distanceMeters']    = (int) round(sqrt($dSq));
                        $aOut['timeOffsetMinutes'] = (int) round(($a['_ts'] - $jamTs) / 60);
                        $aOut['typeLabel']         = $this->labelAlertType((string) $a['type']);
                        $out[$jamId][]             = $aOut;
                    }
                }
            }

            usort($out[$jamId], static fn ($a, $b) => $a['distanceMeters'] <=> $b['distanceMeters']);
        }

        return $out;
    }

    private function loadAlertCorrelation(?Partner $partner, array $filters): array
    {
        // Amostra dos jams mais recentes (com path) para calcular correlação
        $sample = $this->loadRecentJamsForCorrelation($partner, $filters, self::CORRELATION_SAMPLE);

        if ($sample === []) {
            return [
                'sampled'         => 0,
                'withAlerts'      => 0,
                'withAlertsPct'   => 0.0,
                'avgDistanceM'    => 0,
                'avgTimeOffsetM'  => 0,
                'byType'          => [],
                'topCorrelated'   => [],
            ];
        }

        $withAlerts = 0;
        $distSum    = 0;
        $timeSum    = 0;
        $distCount  = 0;
        $typeCount  = [];
        $topCorr    = [];

        foreach ($sample as $j) {
            $n = (int) ($j['nearbyAlerts'] ?? 0);
            if ($n <= 0) continue;
            $withAlerts++;

            $first = $j['alerts'][0] ?? null;

            if ($first) {
                $distSum   += (int) $first['distanceMeters'];
                $timeSum   += abs((int) $first['timeOffsetMinutes']);
                $distCount++;
            }

            foreach ($j['alerts'] as $a) {
                $t = (string) ($a['type'] ?? 'OTHER');
                $typeCount[$t] = ($typeCount[$t] ?? 0) + 1;
            }

            $topCorr[] = [
                'id'           => (int) $j['id'],
                'street'       => $j['street'],
                'city'         => $j['city'],
                'level'        => (int) $j['level'],
                'delayMin'     => $j['delayMin'],
                'when'         => $j['when'],
                'nearbyAlerts' => $n,
                'topAlert'     => $first ? [
                    'type'              => $first['type'],
                    'typeLabel'         => $first['typeLabel'],
                    'distanceMeters'    => $first['distanceMeters'],
                    'timeOffsetMinutes' => $first['timeOffsetMinutes'],
                ] : null,
            ];
        }

        usort($topCorr, static fn ($a, $b) => $b['nearbyAlerts'] <=> $a['nearbyAlerts']);
        $topCorr = array_slice($topCorr, 0, 10);

        arsort($typeCount);
        $byType = [];
        foreach ($typeCount as $type => $count) {
            $byType[] = [
                'type'  => $type,
                'label' => $this->labelAlertType($type),
                'count' => $count,
            ];
        }

        $sampled = count($sample);

        return [
            'sampled'        => $sampled,
            'withAlerts'     => $withAlerts,
            'withAlertsPct'  => $sampled > 0 ? round(($withAlerts / $sampled) * 100, 1) : 0.0,
            'avgDistanceM'   => $distCount > 0 ? (int) round($distSum / $distCount) : 0,
            'avgTimeOffsetM' => $distCount > 0 ? (int) round($timeSum / $distCount) : 0,
            'byType'         => $byType,
            'topCorrelated'  => $topCorr,
        ];
    }

    private function loadRecentJamsForCorrelation(?Partner $partner, array $filters, int $limit): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = $this->sqlBlocked('j');
        $stale   = $this->sqlStale('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.id, j.uuid, j.street, j.city, j.country,
                j.level, j.delay, j.length, j.speed_kmh, j.line_points,
                j.line, j.collected_at, j.last_seen_at, j.is_active,
                ({$blocked}) AS is_blocked,
                ({$stale})   AS is_stale
             FROM waze_jams j
             {$where}
               AND j.line IS NOT NULL
               AND j.level >= 3
             ORDER BY j.collected_at DESC
             LIMIT " . (int) $limit,
            $params
        )->fetchAllAssociative();

        $mapped = array_map([$this, 'mapRow'], $rows);
        return $this->attachNearbyAlerts($mapped);
    }

    // ═══════════════════════════════════════════════════════════════════
    // GEOMETRIA
    // ═══════════════════════════════════════════════════════════════════

    private function minDistanceSqToPolyline(float $lat, float $lng, array $path): float
    {
        $best = PHP_FLOAT_MAX;
        $n    = count($path);
        for ($i = 0; $i < $n - 1; $i++) {
            [$lat1, $lng1] = $path[$i];
            [$lat2, $lng2] = $path[$i + 1];
            $d = $this->pointSegmentDistanceSq($lat, $lng, $lat1, $lng1, $lat2, $lng2);
            if ($d < $best) $best = $d;
        }
        if ($n === 1) {
            [$lat1, $lng1] = $path[0];
            $d = $this->pointSegmentDistanceSq($lat, $lng, $lat1, $lng1, $lat1, $lng1);
            if ($d < $best) $best = $d;
        }
        return $best;
    }

    private function pointSegmentDistanceSq(
        float $px, float $py,
        float $ax, float $ay,
        float $bx, float $by,
    ): float {
        $mLat = 111_320.0;
        $mLng = 111_320.0 * cos(deg2rad($ax));

        $pxM = ($px - $ax) * $mLat;
        $pyM = ($py - $ay) * $mLng;
        $bxM = ($bx - $ax) * $mLat;
        $byM = ($by - $ay) * $mLng;

        $len2 = $bxM * $bxM + $byM * $byM;
        if ($len2 < 1e-9) return $pxM * $pxM + $pyM * $pyM;

        $t  = max(0.0, min(1.0, ($pxM * $bxM + $pyM * $byM) / $len2));
        $cx = $bxM * $t;
        $cy = $byM * $t;

        return ($pxM - $cx) ** 2 + ($pyM - $cy) ** 2;
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function normalizeFilters(array $in): array
    {
        $tz  = new \DateTimeZone(self::TIMEZONE);
        $now = new \DateTimeImmutable('now', $tz);

        $fromStr = trim((string) ($in['date_from'] ?? $now->modify('-7 days')->format('Y-m-d')));
        $toStr   = trim((string) ($in['date_to']   ?? $now->format('Y-m-d')));

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $fromStr, $tz) ?: $now->modify('-7 days');
        $to   = \DateTimeImmutable::createFromFormat('!Y-m-d', $toStr,   $tz) ?: $now;

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $days = (int) $from->diff($to)->days + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            $from = $to->modify('-' . (self::MAX_RANGE_DAYS - 1) . ' days');
            $days = self::MAX_RANGE_DAYS;
        }

        $bucket = match (true) {
            $days <= 3  => 'hour',
            $days <= 60 => 'day',
            default     => 'week',
        };

        return [
            'date_from'        => $from->format('Y-m-d'),
            'date_to'          => $to->format('Y-m-d'),
            'days'             => $days,
            'bucket'           => $bucket,
            'level_min'        => max(0, min(5, (int) ($in['level_min'] ?? 0))),
            'city'             => mb_substr(trim((string) ($in['city'] ?? '')), 0, 100),
            'street'           => mb_substr(trim((string) ($in['street'] ?? '')), 0, 100),
            'only_blocked'     => filter_var($in['only_blocked'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'include_inactive' => filter_var($in['include_inactive'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function buildWhere(?Partner $partner, array $filters, string $alias = 'j'): array
    {
        $parts  = ['1=1'];
        $params = [];

        if ($partner !== null) {
            $parts[]       = "{$alias}.partner_id = :pid";
            $params['pid'] = $partner->getId();
        }

        $tz = new \DateTimeZone(self::TIMEZONE);
        $fromUtc = (new \DateTimeImmutable($filters['date_from'] . ' 00:00:00', $tz))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $toUtc = (new \DateTimeImmutable($filters['date_to'] . ' 23:59:59', $tz))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $parts[]              = "{$alias}.collected_at BETWEEN :fromUtc AND :toUtc";
        $params['fromUtc']    = $fromUtc;
        $params['toUtc']      = $toUtc;

        if (!$filters['include_inactive']) {
            $parts[] = "{$alias}.is_active = 1";
        }

        if ($filters['level_min'] > 0) {
            $parts[]       = "{$alias}.level >= :lvl";
            $params['lvl'] = $filters['level_min'];
        }

        if ($filters['city'] !== '') {
            $parts[]        = "{$alias}.city = :city";
            $params['city'] = $filters['city'];
        }

        if ($filters['street'] !== '') {
            $parts[]          = "{$alias}.street LIKE :street";
            $params['street'] = '%' . $filters['street'] . '%';
        }

        if ($filters['only_blocked']) {
            $parts[] = $this->sqlBlocked($alias);
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    /**
     * Converte UTC → hora local do timezone configurado, dentro do SQL.
     * Como não sabemos o offset salvo no banco, usamos CONVERT_TZ com
     * o offset calculado no PHP para o dia atual.
     */
    private function tzShiftSql(string $column): string
    {
        $tz     = new \DateTimeZone(self::TIMEZONE);
        $offset = (new \DateTimeImmutable('now', $tz))->format('P'); // ex.: -03:00

        return "CONVERT_TZ({$column}, '+00:00', '{$offset}')";
    }

    private function sqlBlocked(string $alias = 'j'): string
    {
        return "({$alias}.level = 5 OR {$alias}.delay = -1)";
    }

    private function sqlStale(string $alias = 'j'): string
    {
        $min = self::STALE_MINUTES;

        return '('
             . $this->sqlBlocked($alias)
             . " AND {$alias}.last_seen_at < UTC_TIMESTAMP() - INTERVAL {$min} MINUTE"
             . ')';
    }

    private function labelAlertType(string $type): string
    {
        return match (strtoupper($type)) {
            'ACCIDENT'      => 'Acidente',
            'JAM'           => 'Congestionamento',
            'ROAD_CLOSED'   => 'Via fechada',
            'POLICE'        => 'Polícia',
            'WEATHERHAZARD' => 'Perigo climático',
            'HAZARD'        => 'Perigo',
            'CONSTRUCTION'  => 'Obra',
            default         => $type !== '' ? $type : 'Alerta',
        };
    }

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
}
