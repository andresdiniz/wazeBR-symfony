<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;

/**
 * Agregações para a página /jams.
 *
 * ─── Regras de negócio ────────────────────────────────────────────
 *   Interdição total:   level = 5  OR  delay = -1
 *   Interdição ATIVA:   interdição com last_seen_at recente (< STALE_MINUTES)
 *   Interdição ANTIGA:  interdição com last_seen_at antigo (>= STALE_MINUTES)
 */
final class JamRepository
{
    private const MAX_LEVEL    = 5;
    private const MAP_LIMIT    = 500;
    private const TABLE_LIMIT  = 30;
    private const EXPORT_LIMIT = 10_000;

    /** Minutos sem atualização para uma interdição virar "antiga". */
    public const STALE_MINUTES = 15;

    /** Defaults para o cruzamento jam ↔ alerta. */
    public const NEARBY_WINDOW_MIN = 30;
    public const NEARBY_RADIUS_M   = 300;

    /**
     * Tamanho de célula do grid geográfico usado no cruzamento jam↔alerta.
     * ~1 km em graus (~1/111). Aumentar = menos células mas mais falsos positivos.
     */
    private const GEO_CELL_DEG = 0.009; // ≈ 1 km

    public function __construct(private readonly Connection $connection)
    {
    }

    // ═══════════════════════════════════════════════════════════════════
    // PUBLIC API — dashboard / wallboard
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string,mixed> */
    public function getWallboard(?Partner $partner, array $filters = []): array
    {
        return $this->getDashboard($partner, $filters);
    }

    /** @return array<string,mixed> */
    public function getDashboard(?Partner $partner, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        return [
            'summary'       => $this->loadSummary($partner, $filters),
            'byHour'        => $this->loadByHour($partner, $filters),
            'blockedActive' => $this->loadBlockedActive($partner, $filters),
            'blockedStale'  => $this->loadBlockedStale($partner, $filters),
            'topJams'       => $this->loadTopJams($partner, $filters),
            'topStreets'    => $this->loadTopStreets($partner, $filters),
            'topCities'     => $this->loadTopCities($partner, $filters),
            'map'           => $this->loadMap($partner, $filters),
            'fetch'         => $this->loadFreshest($partner),
            'generatedAt'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // CIDADES — movido do controller (#6)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Lista distinta de cidades para o <select> do filtro, ordenadas por frequência.
     *
     * @return array<string,int>  ['Cidade' => count, ...]
     */
    public function fetchDistinctCities(?Partner $partner, int $limit = 30): array
    {
        $pf     = '';
        $params = [];

        if ($partner !== null) {
            $pf                = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        try {
            $rows = $this->connection->executeQuery(
                "SELECT city, COUNT(*) AS n
                 FROM waze_jams
                 WHERE is_active = 1
                   AND city IS NOT NULL
                   AND city <> ''
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

    // ═══════════════════════════════════════════════════════════════════
    // SHOW — detalhes de um jam + cruzamento com alertas próximos
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string,mixed>|null */
    public function findJamById(int $id, ?Partner $partner): ?array
    {
        $pf     = '';
        $params = ['id' => $id];

        if ($partner !== null) {
            $pf              = ' AND j.partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $row = $this->connection->executeQuery(
            "SELECT
                j.id, j.uuid, j.street, j.city, j.country,
                j.level, j.delay, j.length, j.speed_kmh,
                j.line_points, j.line,
                j.collected_at, j.last_seen_at,
                " . self::sqlBlocked('j') . " AS is_blocked,
                " . self::sqlStale('j')   . " AS is_stale
             FROM waze_jams j
             WHERE j.id = :id {$pf}
               AND j.is_active = 1
             LIMIT 1",
            $params
        )->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $mapped = $this->mapRow($row);

        // Reconstrói path
        $line = $row['line'];
        if (is_string($line)) {
            $decoded = json_decode($line, true);
            $line    = is_array($decoded) ? $decoded : [];
        }

        $path = [];
        foreach ((is_array($line) ? $line : []) as $pt) {
            if (is_array($pt) && isset($pt['x'], $pt['y'])) {
                $path[] = [(float) $pt['y'], (float) $pt['x']];
            } elseif (is_array($pt) && count($pt) >= 2) {
                $path[] = [(float) $pt[1], (float) $pt[0]];
            }
        }
        $mapped['path'] = $path;

        return $mapped;
    }

    /**
     * Alertas próximos de um único jam.
     *
     * @param  array<string,mixed> $jam
     * @return array<int,array<string,mixed>>
     */
    public function findNearbyAlertsForJam(
        array $jam,
        int $windowMinutes = self::NEARBY_WINDOW_MIN,
        int $radiusMeters  = self::NEARBY_RADIUS_M,
    ): array {
        if (($jam['path'] ?? []) === []) {
            return [];
        }

        $result = $this->findNearbyAlertsForJams([$jam], $windowMinutes, $radiusMeters);

        return $result[(int) $jam['id']] ?? [];
    }

    /**
     * Enriquece uma lista de jams com:
     *   - 'nearbyAlerts' (int)         → contagem total
     *   - 'alerts'       (array curto) → 5 mais próximos p/ popup do mapa
     *
     * @param  array<int,array<string,mixed>> $jams
     * @return array<int,array<string,mixed>>
     */
    public function attachNearbyAlerts(
        array $jams,
        int $windowMinutes = self::NEARBY_WINDOW_MIN,
        int $radiusMeters  = self::NEARBY_RADIUS_M,
    ): array {
        if ($jams === []) {
            return $jams;
        }

        $byJam = $this->findNearbyAlertsForJams($jams, $windowMinutes, $radiusMeters);

        return array_map(static function (array $j) use ($byJam) {
            $alerts            = $byJam[(int) $j['id']] ?? [];
            $j['nearbyAlerts'] = count($alerts);
            $j['alerts']       = array_slice($alerts, 0, 5);

            return $j;
        }, $jams);
    }

    /**
     * Algoritmo batched com grid geográfico (#10):
     *   1. Carrega alertas via bbox + janela temporal
     *   2. Indexa alertas por célula de grade (≈ 1 km)
     *   3. Para cada jam, verifica apenas células vizinhas (9 células)
     *
     * Complexidade: O(J * 9 * density) em vez de O(J * A).
     * Para A=5000 e células com densidade 10: ~45× mais rápido.
     *
     * @param  array<int,array<string,mixed>> $jams
     * @return array<int,array<int,array<string,mixed>>> [jamId => alerts...]
     */
    public function findNearbyAlertsForJams(
        array $jams,
        int $windowMinutes,
        int $radiusMeters,
    ): array {
        if ($jams === []) {
            return [];
        }

        // ── 1. Bbox + janela temporal ──────────────────────────────
        $minLat = $minLng = PHP_FLOAT_MAX;
        $maxLat = $maxLng = -PHP_FLOAT_MAX;
        $minTs  = PHP_INT_MAX;
        $maxTs  = PHP_INT_MIN;

        foreach ($jams as $j) {
            foreach ($j['path'] ?? [] as $pt) {
                if (!is_array($pt) || count($pt) < 2) {
                    continue;
                }
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

        $pad    = $radiusMeters / 111_000;
        $minLat -= $pad;
        $maxLat += $pad;
        $minLng -= $pad;
        $maxLng += $pad;

        $from = (new \DateTimeImmutable('@' . ($minTs - $windowMinutes * 60)))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to   = (new \DateTimeImmutable('@' . ($maxTs + $windowMinutes * 60)))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        // ── 2. Query única de alertas ──────────────────────────────
        $rows = $this->connection->executeQuery(
            "SELECT id, uuid, type, subtype, street, city,
                    latitude, longitude, confidence,
                    collected_at, last_seen_at
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

        // ── 3. Indexa alertas no grid ──────────────────────────────
        $cell    = self::GEO_CELL_DEG;
        $grid    = [];    // ['cx,cy' => [alert, ...]]
        $alertsP = [];    // alertas pré-processados

        foreach ($rows as $r) {
            $lat = (float) $r['latitude'];
            $lng = (float) $r['longitude'];

            if ($lat === 0.0 && $lng === 0.0) {
                continue;
            }

            $ts = strtotime((string) $r['collected_at']);
            if ($ts === false) {
                continue;
            }

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
            $k  = "{$cx},{$cy}";

            $grid[$k][]  = $a;
            $alertsP[]   = $a;
        }

        // ── 4. Para cada jam, consulta apenas as 9 células vizinhas ─
        $result   = [];
        $radiusSq = $radiusMeters * $radiusMeters;

        foreach ($jams as $j) {
            $jamId = (int) $j['id'];
            $result[$jamId] = [];

            $jamTs = strtotime((string) ($j['when'] ?? ''));
            if ($jamTs === false) {
                continue;
            }

            $path = $j['path'] ?? [];
            if ($path === []) {
                continue;
            }

            // Bbox do jam para escolher células
            $jMinLat = $jMinLng = PHP_FLOAT_MAX;
            $jMaxLat = $jMaxLng = -PHP_FLOAT_MAX;
            foreach ($path as $pt) {
                $jMinLat = min($jMinLat, (float) $pt[0]);
                $jMaxLat = max($jMaxLat, (float) $pt[0]);
                $jMinLng = min($jMinLng, (float) $pt[1]);
                $jMaxLng = max($jMaxLng, (float) $pt[1]);
            }

            // Células que cobrem o bbox + 1 de padding
            $cxMin = (int) floor(($jMinLat - $pad) / $cell);
            $cxMax = (int) floor(($jMaxLat + $pad) / $cell);
            $cyMin = (int) floor(($jMinLng - $pad) / $cell);
            $cyMax = (int) floor(($jMaxLng + $pad) / $cell);

            // Coleta candidatos únicos das células relevantes
            $seenIds    = [];
            $candidates = [];

            for ($cx = $cxMin; $cx <= $cxMax; $cx++) {
                for ($cy = $cyMin; $cy <= $cyMax; $cy++) {
                    foreach ($grid["{$cx},{$cy}"] ?? [] as $a) {
                        if (isset($seenIds[$a['id']])) {
                            continue;
                        }
                        $seenIds[$a['id']] = true;
                        $candidates[]      = $a;
                    }
                }
            }

            // Filtra por janela temporal + distância real
            foreach ($candidates as $a) {
                if (abs($a['_ts'] - $jamTs) > $windowMinutes * 60) {
                    continue;
                }

                $distSq = $this->minDistanceSqToPolyline($a['lat'], $a['lng'], $path);
                if ($distSq > $radiusSq) {
                    continue;
                }

                $aOut                       = $a;
                unset($aOut['_ts']);
                $aOut['distanceMeters']    = (int) round(sqrt($distSq));
                $aOut['timeOffsetMinutes'] = (int) round(($a['_ts'] - $jamTs) / 60);
                $aOut['typeLabel']         = $this->labelAlertType((string) $a['type']);
                $result[$jamId][]          = $aOut;
            }

            usort(
                $result[$jamId],
                static fn ($a, $b) => $a['distanceMeters'] <=> $b['distanceMeters']
            );
        }

        return $result;
    }

    /**
     * Distância mínima (ao quadrado) de um ponto para a polyline.
     *
     * @param array<int,array{0:float,1:float}> $path [[lat,lng], ...]
     */
    private function minDistanceSqToPolyline(float $lat, float $lng, array $path): float
    {
        $bestSq = PHP_FLOAT_MAX;
        $n      = count($path);

        for ($i = 0; $i < $n - 1; $i++) {
            [$lat1, $lng1] = $path[$i];
            [$lat2, $lng2] = $path[$i + 1];

            $d = $this->pointSegmentDistanceSq($lat, $lng, $lat1, $lng1, $lat2, $lng2);
            if ($d < $bestSq) {
                $bestSq = $d;
            }
        }

        if ($n === 1) {
            [$lat1, $lng1] = $path[0];
            $d = $this->pointSegmentDistanceSq($lat, $lng, $lat1, $lng1, $lat1, $lng1);
            if ($d < $bestSq) {
                $bestSq = $d;
            }
        }

        return $bestSq;
    }

    /**
     * Distância ponto→segmento em metros² (projeção equiretangular).
     * Erro < 1 % para distâncias < 5 km.
     */
    private function pointSegmentDistanceSq(
        float $px, float $py,
        float $ax, float $ay,
        float $bx, float $by,
    ): float {
        $mPerDegLat = 111_320.0;
        $mPerDegLng = 111_320.0 * cos(deg2rad($ax));

        $pxM = ($px - $ax) * $mPerDegLat;
        $pyM = ($py - $ay) * $mPerDegLng;
        $bxM = ($bx - $ax) * $mPerDegLat;
        $byM = ($by - $ay) * $mPerDegLng;

        $dx   = $bxM;
        $dy   = $byM;
        $len2 = $dx * $dx + $dy * $dy;

        if ($len2 < 1e-9) {
            return $pxM * $pxM + $pyM * $pyM;
        }

        $t  = max(0.0, min(1.0, ($pxM * $dx + $pyM * $dy) / $len2));
        $cx = $dx * $t;
        $cy = $dy * $t;

        return ($pxM - $cx) ** 2 + ($pyM - $cy) ** 2;
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

    // ═══════════════════════════════════════════════════════════════════
    // EXPORT (#11 — line_points garantido no SELECT)
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<int,array<string,mixed>> */
    public function loadExportRows(?Partner $partner, array $filters = []): array
    {
        $filters    = $this->normalizeFilters($filters);
        [$where, $params] = $this->buildWhere($partner, $filters);

        $blockedSql = self::sqlBlocked('j');
        $staleSql   = self::sqlStale('j');

        return $this->connection->executeQuery(
            "SELECT
                j.id,
                j.uuid,
                j.street,
                j.city,
                j.country,
                j.level,
                j.delay,
                j.length,
                j.speed_kmh,
                j.line_points,
                j.pub_millis,
                j.collected_at,
                j.last_seen_at,
                ({$blockedSql}) AS is_blocked,
                ({$staleSql})   AS is_stale
             FROM waze_jams j
             {$where}
               AND j.is_active = 1
             ORDER BY is_blocked DESC, is_stale ASC, j.level DESC, j.delay DESC, j.collected_at DESC
             LIMIT " . self::EXPORT_LIMIT,
            $params
        )->fetchAllAssociative();
    }

    // ═══════════════════════════════════════════════════════════════════
    // FILTERS
    // ═══════════════════════════════════════════════════════════════════

    private function normalizeFilters(array $filters): array
    {
        return [
            'level_min'    => max(0, min(self::MAX_LEVEL, (int) ($filters['level_min'] ?? 1))),
            'city'         => mb_substr((string) ($filters['city'] ?? ''), 0, 100),
            'street'       => mb_substr((string) ($filters['street'] ?? ''), 0, 100),
            'window_hours' => max(1, min(48, (int) ($filters['window_hours'] ?? 2))),
            'only_blocked' => (bool) ($filters['only_blocked'] ?? false),
            'hide_stale'   => (bool) ($filters['hide_stale'] ?? false),
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
            $parts[] = self::sqlBlocked($alias);
        }

        if ($filters['hide_stale']) {
            $parts[] = 'NOT ' . self::sqlStale($alias);
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    private static function sqlBlocked(string $alias = 'j'): string
    {
        return "({$alias}.level = 5 OR {$alias}.delay = -1)";
    }

    private static function sqlStale(string $alias = 'j'): string
    {
        $min = self::STALE_MINUTES;

        return '('
            . self::sqlBlocked($alias)
            . " AND {$alias}.last_seen_at < UTC_TIMESTAMP() - INTERVAL {$min} MINUTE"
            . ')';
    }

    // ═══════════════════════════════════════════════════════════════════
    // AGREGAÇÕES — dashboard
    // ═══════════════════════════════════════════════════════════════════

    private function loadSummary(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = self::sqlBlocked('j');
        $stale   = self::sqlStale('j');

        $row = $this->connection->executeQuery(
            "SELECT
                COUNT(*)                                                    AS total,
                SUM(j.level = 1)                                            AS lvl1,
                SUM(j.level = 2)                                            AS lvl2,
                SUM(j.level = 3)                                            AS lvl3,
                SUM(j.level = 4)                                            AS lvl4,
                SUM(j.level = 5)                                            AS lvl5,
                SUM({$blocked})                                             AS blocked,
                SUM({$blocked} AND NOT {$stale})                            AS blocked_active,
                SUM({$stale})                                               AS blocked_stale,
                COALESCE(AVG(CASE WHEN j.delay >= 0 THEN j.delay END), 0)   AS avg_delay,
                COALESCE(MAX(CASE WHEN j.delay >= 0 THEN j.delay END), 0)   AS max_delay,
                COALESCE(AVG(j.speed_kmh), 0)                               AS avg_speed,
                COALESCE(SUM(j.length), 0)                                  AS total_length,
                MAX(j.collected_at)                                         AS last_seen
             FROM waze_jams j
             {$where}
               AND j.is_active = 1",
            $params
        )->fetchAssociative() ?: [];

        return [
            'total'         => (int)   ($row['total']        ?? 0),
            'byLevel'       => [
                1 => (int) ($row['lvl1'] ?? 0),
                2 => (int) ($row['lvl2'] ?? 0),
                3 => (int) ($row['lvl3'] ?? 0),
                4 => (int) ($row['lvl4'] ?? 0),
                5 => (int) ($row['lvl5'] ?? 0),
            ],
            'blocked'       => (int)   ($row['blocked']        ?? 0),
            'blockedActive' => (int)   ($row['blocked_active'] ?? 0),
            'blockedStale'  => (int)   ($row['blocked_stale']  ?? 0),
            'avgDelay'      => (int) round((float) ($row['avg_delay'] ?? 0)),
            'maxDelay'      => (int)   ($row['max_delay']    ?? 0),
            'avgSpeed'      => (int) round((float) ($row['avg_speed'] ?? 0)),
            'totalLength'   => (int)   ($row['total_length'] ?? 0),
            'lastSeen'      => $this->toIso($row['last_seen'] ?? null),
        ];
    }

    private function loadByHour(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $hours = $filters['window_hours'];

        $rows = $this->connection->executeQuery(
            "SELECT
                DATE_FORMAT(j.collected_at, '%Y-%m-%d %H:00:00') AS bucket,
                COUNT(*) AS cnt
             FROM waze_jams j
             {$where}
               AND j.is_active = 1
               AND j.collected_at >= UTC_TIMESTAMP() - INTERVAL {$hours} HOUR
             GROUP BY bucket",
            $params
        )->fetchAllAssociative();

        $lookup = [];
        foreach ($rows as $r) {
            $lookup[(string) $r['bucket']] = (int) $r['cnt'];
        }

        $out    = [];
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTime((int) date('H'), 0, 0);

        for ($i = $hours - 1; $i >= 0; $i--) {
            $moment = $nowUtc->modify("-{$i} hour");
            $key    = $moment->format('Y-m-d H:00:00');
            $out[]  = [
                'at'    => $moment->format('Y-m-d\TH:i:s\Z'),
                'count' => $lookup[$key] ?? 0,
            ];
        }

        return $out;
    }

    private function loadBlockedActive(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = self::sqlBlocked('j');
        $stale   = self::sqlStale('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.id, j.uuid, j.street, j.city, j.country,
                j.level, j.delay, j.length, j.speed_kmh,
                j.line_points,
                j.collected_at, j.last_seen_at,
                1 AS is_blocked, 0 AS is_stale
             FROM waze_jams j
             {$where}
               AND j.is_active = 1
               AND {$blocked}
               AND NOT {$stale}
             ORDER BY j.level DESC, j.delay DESC, j.collected_at DESC
             LIMIT " . self::TABLE_LIMIT,
            $params
        )->fetchAllAssociative();

        return array_map([$this, 'mapRow'], $rows);
    }

    private function loadBlockedStale(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = self::sqlBlocked('j');
        $stale   = self::sqlStale('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.id, j.uuid, j.street, j.city, j.country,
                j.level, j.delay, j.length, j.speed_kmh,
                j.line_points,
                j.collected_at, j.last_seen_at,
                1 AS is_blocked, 1 AS is_stale
             FROM waze_jams j
             {$where}
               AND j.is_active = 1
               AND {$blocked}
               AND {$stale}
             ORDER BY j.last_seen_at DESC, j.level DESC
             LIMIT " . self::TABLE_LIMIT,
            $params
        )->fetchAllAssociative();

        return array_map([$this, 'mapRow'], $rows);
    }

    private function loadTopJams(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = self::sqlBlocked('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.id, j.uuid, j.street, j.city, j.country,
                j.level, j.delay, j.length, j.speed_kmh,
                j.line_points,
                j.collected_at, j.last_seen_at,
                0 AS is_blocked, 0 AS is_stale,
                (j.level * GREATEST(j.delay, 0)) AS score
             FROM waze_jams j
             {$where}
               AND j.is_active = 1
               AND NOT {$blocked}
             ORDER BY score DESC, j.delay DESC, j.collected_at DESC
             LIMIT " . self::TABLE_LIMIT,
            $params
        )->fetchAllAssociative();

        return array_map([$this, 'mapRow'], $rows);
    }

    private function mapRow(array $r): array
    {
        $delay = (int) $r['delay'];

        return [
            'id'         => (int) $r['id'],
            'uuid'       => $r['uuid'],
            'street'     => $r['street'],
            'city'       => $r['city'],
            'country'    => $r['country'],
            'level'      => (int) $r['level'],
            'levelLabel' => $this->labelLevel((int) $r['level']),
            'delay'      => $delay,
            'delayMin'   => $delay >= 0 ? round($delay / 60, 1) : null,
            'length'     => (int) $r['length'],
            'lengthKm'   => round(((int) $r['length']) / 1000, 2),
            'speed'      => (float) $r['speed_kmh'],
            'score'      => (int) ($r['score'] ?? ($r['level'] * max(0, $delay))),
            'points'     => (int) $r['line_points'],
            'isBlocked'  => (bool) ($r['is_blocked'] ?? false),
            'isStale'    => (bool) ($r['is_stale']   ?? false),
            'when'       => $this->toIso($r['collected_at']),
            'lastSeen'   => $this->toIso($r['last_seen_at']),
        ];
    }

    private function loadTopStreets(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = self::sqlBlocked('j');
        $stale   = self::sqlStale('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.street, j.city,
                COUNT(*)                                     AS cnt,
                SUM({$blocked})                              AS blocked,
                SUM({$blocked} AND NOT {$stale})             AS blocked_active,
                SUM({$stale})                                AS blocked_stale,
                MAX(j.level)                                 AS max_level,
                AVG(CASE WHEN j.delay >= 0 THEN j.delay END) AS avg_delay,
                MAX(CASE WHEN j.delay >= 0 THEN j.delay END) AS max_delay
             FROM waze_jams j
             {$where}
               AND j.is_active = 1
               AND j.street IS NOT NULL
               AND j.street <> ''
             GROUP BY j.street, j.city
             ORDER BY blocked_active DESC, blocked_stale DESC, cnt DESC
             LIMIT 10",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'street'        => (string) $r['street'],
            'city'          => (string) ($r['city'] ?? ''),
            'count'         => (int) $r['cnt'],
            'blocked'       => (int) $r['blocked'],
            'blockedActive' => (int) $r['blocked_active'],
            'blockedStale'  => (int) $r['blocked_stale'],
            'maxLevel'      => (int) $r['max_level'],
            'avgDelay'      => (int) round((float) ($r['avg_delay'] ?? 0)),
            'maxDelay'      => (int) ($r['max_delay'] ?? 0),
        ], $rows);
    }

    private function loadTopCities(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = self::sqlBlocked('j');
        $stale   = self::sqlStale('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.city,
                COUNT(*)                                     AS cnt,
                SUM({$blocked})                              AS blocked,
                SUM({$blocked} AND NOT {$stale})             AS blocked_active,
                SUM({$stale})                                AS blocked_stale,
                MAX(j.level)                                 AS max_level,
                AVG(CASE WHEN j.delay >= 0 THEN j.delay END) AS avg_delay
             FROM waze_jams j
             {$where}
               AND j.is_active = 1
               AND j.city IS NOT NULL
               AND j.city <> ''
             GROUP BY j.city
             ORDER BY blocked_active DESC, blocked_stale DESC, cnt DESC
             LIMIT 10",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'city'          => (string) $r['city'],
            'count'         => (int) $r['cnt'],
            'blocked'       => (int) $r['blocked'],
            'blockedActive' => (int) $r['blocked_active'],
            'blockedStale'  => (int) $r['blocked_stale'],
            'maxLevel'      => (int) $r['max_level'],
            'avgDelay'      => (int) round((float) ($r['avg_delay'] ?? 0)),
        ], $rows);
    }

    private function loadMap(?Partner $partner, array $filters): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);
        $blocked = self::sqlBlocked('j');
        $stale   = self::sqlStale('j');

        $rows = $this->connection->executeQuery(
            "SELECT
                j.id, j.level, j.delay, j.length, j.speed_kmh,
                j.street, j.city, j.line,
                ({$blocked}) AS is_blocked,
                ({$stale})   AS is_stale
             FROM waze_jams j
             {$where}
               AND j.is_active = 1
               AND j.line IS NOT NULL
             ORDER BY is_blocked DESC, is_stale ASC, j.level DESC, j.delay DESC
             LIMIT " . self::MAP_LIMIT,
            $params
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $line = $r['line'];
            if (is_string($line)) {
                $decoded = json_decode($line, true);
                $line    = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($line) || $line === []) {
                continue;
            }

            $path = [];
            foreach ($line as $pt) {
                if (is_array($pt) && isset($pt['x'], $pt['y'])) {
                    $path[] = [(float) $pt['y'], (float) $pt['x']];
                } elseif (is_array($pt) && count($pt) >= 2) {
                    $path[] = [(float) $pt[1], (float) $pt[0]];
                }
            }
            if ($path === []) {
                continue;
            }

            $out[] = [
                'id'        => (int) $r['id'],
                'level'     => (int) $r['level'],
                'delay'     => (int) $r['delay'],
                'length'    => (int) $r['length'],
                'speed'     => (float) $r['speed_kmh'],
                'street'    => $r['street'],
                'city'      => $r['city'],
                'isBlocked' => (bool) $r['is_blocked'],
                'isStale'   => (bool) $r['is_stale'],
                'path'      => $path,
            ];
        }

        return [
            'center' => $this->computeCenter($out),
            'jams'   => $out,
        ];
    }

    private function computeCenter(array $jams): array
    {
        if ($jams === []) {
            return ['lat' => -20.6607, 'lng' => -43.7856, 'zoom' => 12, 'hasData' => false];
        }

        $minLat = $minLng = PHP_FLOAT_MAX;
        $maxLat = $maxLng = -PHP_FLOAT_MAX;
        $count  = 0;

        foreach ($jams as $j) {
            foreach ($j['path'] as $pt) {
                $minLat = min($minLat, $pt[0]);
                $maxLat = max($maxLat, $pt[0]);
                $minLng = min($minLng, $pt[1]);
                $maxLng = max($maxLng, $pt[1]);
                $count++;
            }
        }

        if ($count === 0) {
            return ['lat' => -20.6607, 'lng' => -43.7856, 'zoom' => 12, 'hasData' => false];
        }

        $span = max($maxLat - $minLat, $maxLng - $minLng);
        $zoom = match (true) {
            $span < 0.02 => 14,
            $span < 0.05 => 13,
            $span < 0.15 => 12,
            default      => 11,
        };

        return [
            'lat'     => ($minLat + $maxLat) / 2,
            'lng'     => ($minLng + $maxLng) / 2,
            'zoom'    => $zoom,
            'hasData' => true,
        ];
    }

    private function loadFreshest(?Partner $partner): array
    {
        $pf     = '';
        $params = [];

        if ($partner !== null) {
            $pf              = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $row = $this->connection->executeQuery(
            "SELECT
                MAX(collected_at) AS last_collected,
                MIN(collected_at) AS first_collected
             FROM waze_jams
             WHERE is_active = 1 {$pf}",
            $params
        )->fetchAssociative() ?: [];

        return [
            'last'  => $this->toIso($row['last_collected']  ?? null),
            'first' => $this->toIso($row['first_collected'] ?? null),
        ];
    }

    private function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->format(DATE_ATOM);
        }
        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function labelLevel(int $level): string
    {
        return match ($level) {
            0       => 'Livre',
            1       => 'Baixo',
            2       => 'Moderado',
            3       => 'Alto',
            4       => 'Muito alto',
            5       => 'Parado',
            default => 'Nível ' . $level,
        };
    }
}
