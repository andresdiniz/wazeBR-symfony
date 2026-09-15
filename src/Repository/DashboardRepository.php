<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

final class DashboardRepository extends ServiceEntityRepository
{
    private Connection $connection;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
        $this->connection = $registry->getConnection();
    }

    /** @return array<string,mixed> */
    public function getDashboardStats(?Partner $partner = null, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        return [
            'totals'   => $this->getTotals($partner, $filters),
            'kpis'     => $this->getKpis($partner, $filters),
            'charts'   => [
                'hourly_activity'  => $this->getHourlyActivity($partner, $filters),
                'alerts_by_type'   => $this->getAlertsByType($partner, $filters),
                'alerts_by_city'   => $this->getAlertsByCity($partner, $filters),
                'alerts_by_street' => $this->getAlertsByStreet($partner, $filters),
                'jams_by_level'    => $this->getJamsByLevel($partner, $filters),
                'weather_trend'    => $this->getWeatherTrend($partner, $filters),
                'jams_by_hour'     => $this->getJamsByHour($partner, $filters),
            ],
            'map'      => [
                'center'   => $this->getMapCenter($partner),
                'alerts'   => $this->getMapAlerts($partner, $filters),
                'jams'     => $this->getMapJams($partner, $filters),
                'cameras'  => $this->getMapCameras($partner),
                'weather'  => $this->getMapWeatherLocations($partner),
                'stations' => $this->getMapStations($partner),
            ],
            'recent'   => [
                'alerts'  => $this->getRecentAlerts($partner, $filters, 8),
                'jams'    => $this->getRecentJams($partner, $filters, 8),
                'routes'  => $this->getRoutesWithLatestSnapshots($partner, 10),
                'weather' => $this->getRecentWeather($partner, 5),
            ],
            'hydro'    => [
                'stats'             => $this->getHydroStats($partner),
                'trend'             => $this->getHydroTrend($partner, 48),
                'trend_by_station'  => $this->getHydroTrendByStation($partner, 48),
                'stations_list'     => $this->getHydroStationsList($partner),
                'recent'            => $this->getRecentHydro($partner, 20),
            ],
            'pluvio'   => [
                'stats'             => $this->getPluvioStats($partner),
                'hourly'            => $this->getPluvioHourly($partner, 48),
                'hourly_by_station' => $this->getPluvioHourlyByStation($partner, 48),
                'stations'          => $this->getPluvioByStation($partner, 24),
            ],
            'fetch_status' => $this->getPartnerFetchStatus($partner),
            'health_score' => $this->computeHealthScore($partner, $filters),
            'updated_at'   => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'filters'      => $filters,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Filters
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    private function normalizeFilters(array $filters): array
    {
        return [
            'period' => in_array($filters['period'] ?? 'all', ['all','today','week','month','year'], true)
                ? (string) $filters['period'] : 'all',
            'type'   => in_array($filters['type'] ?? 'all', ['all','alerts','jams','weather','routes','hydro','pluvio'], true)
                ? (string) $filters['type'] : 'all',
            'query'  => trim((string) ($filters['query'] ?? '')),
            'city'   => isset($filters['city']) && $filters['city'] !== '' ? trim((string) $filters['city']) : null,
            'exclude_streets' => trim((string) ($filters['exclude_streets'] ?? '')),
        ];
    }

    private function resolveDateFrom(string $period): ?\DateTimeImmutable
{
    if ($period === 'all') return null;

    // Períodos são calculados no fuso do usuário (Brasil).
    // Sem isso, "Hoje" começaria às 21h do dia anterior (meia-noite UTC).
    $tzLocal = new \DateTimeZone('America/Sao_Paulo');
    $tzUtc   = new \DateTimeZone('UTC');

    $now = new \DateTimeImmutable('now', $tzLocal);

    $from = match ($period) {
        'today' => $now->setTime(0, 0),
        'week'  => $now->modify('-7 days'),
        'month' => $now->modify('-30 days'),
        'year'  => $now->modify('-365 days'),
        default => null,
    };

    return $from?->setTimezone($tzUtc);
}

    /**
     * Constrói WHERE + params para as queries do dashboard.
     *
     * @param string[] $queryColumns colunas usadas no LIKE do `query`
     * @return array{0:string,1:array<string,mixed>,2:array<string,int>}
     */
    private function buildWhere(
        ?Partner $partner,
        array $filters,
        string $dateColumn,
        string $partnerColumn = 'partner_id',
        string $cityColumn = 'city',
        bool $applyStreetExclude = false,
        array $queryColumns = ['type', 'subtype', 'city', 'street'],
    ): array {
        $filters = [
            'period'          => $filters['period'] ?? 'all',
            'query'           => $filters['query']  ?? '',
            'city'            => $filters['city']   ?? null,
            'exclude_streets' => $filters['exclude_streets'] ?? '',
        ];

        $where  = ['1=1'];
        $params = [];
        $types  = [];

        if ($partner !== null && $partnerColumn !== '') {
            $where[]              = "t.$partnerColumn = :partner_id";
            $params['partner_id'] = $partner->getId();
            $types['partner_id']  = ParameterType::INTEGER;
        }

        $dateFrom = $this->resolveDateFrom((string) $filters['period']);
        if ($dateFrom !== null && $dateColumn !== '') {
            $where[]             = "t.$dateColumn >= :date_from";
            $params['date_from'] = $dateFrom->format('Y-m-d H:i:s');
        }

        if (!empty($filters['city']) && $cityColumn !== '') {
            $where[]        = "t.$cityColumn = :city";
            $params['city'] = $filters['city'];
        }

        if (!empty($filters['query']) && $queryColumns !== []) {
            $parts = [];
            foreach ($queryColumns as $col) {
                $parts[] = "t.$col LIKE :q";
            }
            $where[]     = '(' . implode(' OR ', $parts) . ')';
            $params['q'] = '%' . $filters['query'] . '%';
        }

        if ($applyStreetExclude && !empty($filters['exclude_streets'])) {
            $patterns = $this->parseExcludePatterns($filters['exclude_streets']);
            if ($patterns !== []) {
                $where[]              = "(t.street IS NULL OR t.street = '' OR t.street NOT REGEXP :exclude_re)";
                $params['exclude_re'] = implode('|', $patterns);
            }
        }

        return [implode(' AND ', $where), $params, $types];
    }

    /** @return list<string> */
    private function parseExcludePatterns(string $input): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $input) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // segurança: limita tamanho de cada padrão e a quantidade
            $out[] = mb_substr($line, 0, 200);
        }
        return array_slice($out, 0, 30);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Totals / KPIs
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    private function getTotals(?Partner $partner, array $filters): array
    {
        [$wAlerts, $pAlerts]     = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true);
        [$wJams,   $pJams]       = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true, ['street', 'city']);
        [$wWeather,$pWeather]    = $this->buildWhere($partner, $filters, 'observed_at', 'partner_id', '', false, []);
        [$wCams,   $pCams]       = $this->buildWhere($partner, [], 'created_at', 'partner_id', '', false, []);
        [$wStations, $pStations] = $this->buildWhere($partner, [], 'created_at', 'partner_id', '', false, []);

        $alerts  = $this->connection->executeQuery(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) AS active
             FROM waze_alerts t WHERE $wAlerts",
            $pAlerts
        )->fetchAssociative() ?: [];

        $jams = $this->connection->executeQuery(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) AS active,
                    AVG(t.delay) AS avg_delay, AVG(t.level) AS avg_level
             FROM waze_jams t WHERE $wJams",
            $pJams
        )->fetchAssociative() ?: [];

        $weather = $this->connection->executeQuery(
            "SELECT COUNT(*) AS total FROM weather_observation t WHERE $wWeather",
            $pWeather
        )->fetchAssociative() ?: [];

        $cams = $this->connection->executeQuery(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) AS active
             FROM partner_camera_link t WHERE $wCams",
            $pCams
        )->fetchAssociative() ?: [];

        $stations = $this->connection->executeQuery(
            "SELECT COUNT(*) AS total FROM cemaden_station_link t WHERE $wStations",
            $pStations
        )->fetchAssociative() ?: [];

        $em = $this->getEntityManager();

        return [
            'alerts'          => (int) ($alerts['total'] ?? 0),
            'alerts_active'   => (int) ($alerts['active'] ?? 0),
            'jams'            => (int) ($jams['total'] ?? 0),
            'jams_active'     => (int) ($jams['active'] ?? 0),
            'jams_avg_delay'  => (float) ($jams['avg_delay'] ?? 0),
            'jams_avg_level'  => (float) ($jams['avg_level'] ?? 0),
            'weather'         => (int) ($weather['total'] ?? 0),
            'cameras'         => (int) ($cams['total'] ?? 0),
            'cameras_active'  => (int) ($cams['active'] ?? 0),
            'stations'        => (int) ($stations['total'] ?? 0),
            'partners'        => (int) $em->getRepository(Partner::class)->count([]),
            'users'           => (int) $em->getRepository(User::class)->count([]),
        ];
    }

    /** @param array<string,mixed> $filters */
    private function getKpis(?Partner $partner, array $filters): array
    {
        $totals = $this->getTotals($partner, $filters);
        $hydro  = $this->getHydroStats($partner);
        $pluvio = $this->getPluvioStats($partner);
        $routes = $this->getRoutesSummary($partner);

        return [
            'alerts'          => $totals['alerts'],
            'alerts_active'   => $totals['alerts_active'],
            'jams'            => $totals['jams'],
            'jams_active'     => $totals['jams_active'],
            'weather'         => $totals['weather'],
            'cameras'         => $totals['cameras'],
            'stations'        => $totals['stations'],
            'partners'        => $totals['partners'],
            'users'           => $totals['users'],
            'hydro_stations'  => $hydro['stations'],
            'hydro_at_risk'   => $hydro['at_risk'],
            'pluvio_stations' => $pluvio['stations'],
            'pluvio_rain_24h' => $pluvio['rain_24h'],
            'pluvio_rain_1h'  => $pluvio['rain_1h'],
            'pluvio_peak_24h' => $pluvio['peak_24h'],
            'routes_total'    => $routes['total'],
            'routes_delayed'  => $routes['delayed'],
            'routes_severe'   => $routes['severe'],
            'health_score'    => $this->computeHealthScore($partner, $filters),
        ];
    }

    private function getRoutesSummary(?Partner $partner): array
    {
        $routes = $this->getRoutesWithLatestSnapshots($partner, 200);

        $total = count($routes);
        $delayed = 0;
        $severe = 0;

        foreach ($routes as $r) {
            if (($r['delay_seconds'] ?? 0) > 0) $delayed++;
            $lvl = $r['delay_level']['level'] ?? 'none';
            if ($lvl === 'severe' || $lvl === 'heavy') $severe++;
        }

        return ['total' => $total, 'delayed' => $delayed, 'severe' => $severe];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Hydro (CEMADEN — nível do rio)
    // ─────────────────────────────────────────────────────────────────────

    private function getHydroStats(?Partner $partner): array
    {
        $params = [];
        $where  = ['h.active = 1'];
        if ($partner !== null) {
            $where[]              = 'h.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $stations = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM cemaden_hidro_station_link h WHERE $w",
            $params
        )->fetchOne();

        $lastFetch = $this->connection->executeQuery(
            "SELECT MAX(h.last_fetched_at) FROM cemaden_hidro_station_link h WHERE $w",
            $params
        )->fetchOne();

        $sql = "SELECT risk, COUNT(*) AS total FROM (
                    SELECT
                        CASE
                            WHEN o.water_level IS NULL THEN 'unknown'
                            WHEN o.cota_transbordamento IS NOT NULL AND o.water_level >= o.cota_transbordamento THEN 'overflow'
                            WHEN o.cota_alerta         IS NOT NULL AND o.water_level >= o.cota_alerta         THEN 'alert'
                            WHEN o.cota_atencao        IS NOT NULL AND o.water_level >= o.cota_atencao        THEN 'attention'
                            ELSE 'normal'
                        END AS risk
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
                    WHERE $w
                ) AS t
                GROUP BY risk";

        $riskRows = $this->connection->executeQuery($sql, $params)->fetchAllKeyValue();

        $overflow  = (int) ($riskRows['overflow']  ?? 0);
        $alert     = (int) ($riskRows['alert']     ?? 0);
        $attention = (int) ($riskRows['attention'] ?? 0);

        return [
            'stations'   => $stations,
            'last_fetch' => $this->toUtc($lastFetch),
            'risk' => [
                'overflow'  => $overflow,
                'alert'     => $alert,
                'attention' => $attention,
                'normal'    => (int) ($riskRows['normal']  ?? 0),
                'unknown'   => (int) ($riskRows['unknown'] ?? 0),
            ],
            'at_risk' => $overflow + $alert + $attention,
        ];
    }

    /** Lista de estações hidro com nível atual e cotas (para o seletor). */
    private function getHydroStationsList(?Partner $partner): array
    {
        $params = [];
        $where  = ['h.active = 1'];
        if ($partner !== null) {
            $where[]              = 'h.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT
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
                WHERE $w
                ORDER BY o.water_level DESC";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(function ($r) {
            $level = $r['water_level'] !== null ? (float) $r['water_level'] : null;
            $trans = $r['cota_transbordamento'] !== null ? (float) $r['cota_transbordamento'] : null;
            $alert = $r['cota_alerta'] !== null ? (float) $r['cota_alerta'] : null;
            $attn  = $r['cota_atencao'] !== null ? (float) $r['cota_atencao'] : null;

            $risk = 'unknown';
            if ($level !== null) {
                if ($trans !== null && $level >= $trans) $risk = 'overflow';
                elseif ($alert !== null && $level >= $alert) $risk = 'alert';
                elseif ($attn !== null && $level >= $attn) $risk = 'attention';
                else $risk = 'normal';
            }

            return [
                'id'                  => (int) $r['id'],
                'name'                => $r['station_name'] ?? 'Estação',
                'city'                => $r['city'],
                'state'               => $r['state'],
                'level'               => $level,
                'cotaAtencao'         => $attn,
                'cotaAlerta'          => $alert,
                'cotaTransbordamento' => $trans,
                'risk'                => $risk,
                'observedAt'          => $this->toUtc($r['observed_at']),
            ];
        }, $rows);
    }

    /** @return list<array<string,mixed>> */
    private function getHydroTrend(?Partner $partner, int $hours = 48): array
    {
        $params = [
            'since' => (new \DateTimeImmutable("-{$hours} hours", new \DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s'),
        ];

        $where = [
            "o.observation_type = 'level'",
            'o.water_level IS NOT NULL',
            'o.observed_at >= :since',
        ];

        if ($partner !== null) {
            $where[]              = 'o.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT
                    DATE_FORMAT(DATE_ADD(o.observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    AVG(o.water_level) AS avg_level,
                    MAX(o.water_level) AS max_level,
                    MIN(o.water_level) AS min_level,
                    AVG(o.cota_atencao)         AS avg_cota_atencao,
                    AVG(o.cota_alerta)          AS avg_cota_alerta,
                    AVG(o.cota_transbordamento) AS avg_cota_transb
                FROM cemaden_hidro_observation o
                WHERE $w
                GROUP BY bucket
                ORDER BY bucket ASC
                LIMIT 96";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'time'          => (string) $r['bucket'],
            'avg_level'     => $r['avg_level'] !== null ? round((float) $r['avg_level'], 3) : null,
            'max_level'     => $r['max_level'] !== null ? round((float) $r['max_level'], 3) : null,
            'min_level'     => $r['min_level'] !== null ? round((float) $r['min_level'], 3) : null,
            'cota_atencao'  => $r['avg_cota_atencao'] !== null ? round((float) $r['avg_cota_atencao'], 3) : null,
            'cota_alerta'   => $r['avg_cota_alerta']  !== null ? round((float) $r['avg_cota_alerta'], 3)  : null,
            'cota_transb'   => $r['avg_cota_transb']  !== null ? round((float) $r['avg_cota_transb'], 3)  : null,
        ], $rows);
    }

    /**
     * Trend por estação — retorna mapa [stationId => list<point>].
     */
    private function getHydroTrendByStation(?Partner $partner, int $hours = 48): array
    {
        $params = [
            'since' => (new \DateTimeImmutable("-{$hours} hours", new \DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s'),
        ];

        $where = [
            "o.observation_type = 'level'",
            'o.water_level IS NOT NULL',
            'o.observed_at >= :since',
        ];

        if ($partner !== null) {
            $where[]              = 'o.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT
                    o.cemaden_hidro_station_link_id AS station_id,
                    DATE_FORMAT(DATE_ADD(o.observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    AVG(o.water_level)          AS avg_level,
                    AVG(o.cota_atencao)         AS avg_cota_atencao,
                    AVG(o.cota_alerta)          AS avg_cota_alerta,
                    AVG(o.cota_transbordamento) AS avg_cota_transb
                FROM cemaden_hidro_observation o
                WHERE $w
                GROUP BY station_id, bucket
                ORDER BY station_id ASC, bucket ASC";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['station_id'];
            $out[$sid][] = [
                'time'         => (string) $r['bucket'],
                'avg_level'    => $r['avg_level'] !== null ? round((float) $r['avg_level'], 3) : null,
                'cota_atencao' => $r['avg_cota_atencao'] !== null ? round((float) $r['avg_cota_atencao'], 3) : null,
                'cota_alerta'  => $r['avg_cota_alerta']  !== null ? round((float) $r['avg_cota_alerta'], 3)  : null,
                'cota_transb'  => $r['avg_cota_transb']  !== null ? round((float) $r['avg_cota_transb'], 3)  : null,
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function getRecentHydro(?Partner $partner, int $limit = 20): array
    {
        $params = [];
        $where  = ["o.observation_type = 'level'"];
        if ($partner !== null) {
            $where[]              = 'o.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT
                    o.id,
                    o.cemaden_hidro_station_link_id AS station_id,
                    o.station_name,
                    o.city,
                    o.state,
                    o.observed_at,
                    o.water_level,
                    o.raw_value,
                    o.offset_value,
                    o.cota_atencao,
                    o.cota_alerta,
                    o.cota_transbordamento
                FROM cemaden_hidro_observation o
                WHERE $w
                  AND o.id = (
                    SELECT o2.id FROM cemaden_hidro_observation o2
                    WHERE o2.cemaden_hidro_station_link_id = o.cemaden_hidro_station_link_id
                      AND o2.observation_type = 'level'
                    ORDER BY o2.observed_at DESC, o2.id DESC
                    LIMIT 1
                  )
                ORDER BY o.observed_at DESC
                LIMIT " . (int) $limit;

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(function ($r) {
            $level = $r['water_level'] !== null ? (float) $r['water_level'] : null;
            $trans = $r['cota_transbordamento'] !== null ? (float) $r['cota_transbordamento'] : null;
            $alert = $r['cota_alerta'] !== null ? (float) $r['cota_alerta'] : null;
            $attn  = $r['cota_atencao'] !== null ? (float) $r['cota_atencao'] : null;

            $risk = 'unknown';
            if ($level !== null) {
                if ($trans !== null && $level >= $trans) $risk = 'overflow';
                elseif ($alert !== null && $level >= $alert) $risk = 'alert';
                elseif ($attn !== null && $level >= $attn) $risk = 'attention';
                else $risk = 'normal';
            }

            return [
                'id'                  => (int) $r['id'],
                'stationId'           => (int) $r['station_id'],
                'stationName'         => $r['station_name'],
                'city'                => $r['city'],
                'state'               => $r['state'],
                'observedAt'          => $this->toUtc($r['observed_at']),
                'waterLevel'          => $level,
                'rawValue'            => $r['raw_value'] !== null ? (float) $r['raw_value'] : null,
                'cotaAtencao'         => $attn,
                'cotaAlerta'          => $alert,
                'cotaTransbordamento' => $trans,
                'risk'                => $risk,
            ];
        }, $rows);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pluviométrico
    // ─────────────────────────────────────────────────────────────────────

    private function getPluvioStats(?Partner $partner): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since1h  = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $since24h = $now->modify('-24 hours')->format('Y-m-d H:i:s');
        $since7d  = $now->modify('-7 days')->format('Y-m-d H:i:s');

        $pParams = ['since_1h' => $since1h, 'since_24h' => $since24h, 'since_7d' => $since7d];
        $pWhere  = ['s.active = 1'];
        if ($partner !== null) {
            $pWhere[]              = 's.partner_id = :partner_id';
            $pParams['partner_id'] = $partner->getId();
        }
        $wp = implode(' AND ', $pWhere);

        $pluvio = $this->connection->executeQuery(
            "SELECT
                COUNT(DISTINCT s.id) AS stations,
                COALESCE(SUM(o.accumulated_rainfall), 0) AS rain_7d,
                COALESCE(SUM(CASE WHEN o.observed_at >= :since_24h THEN o.accumulated_rainfall END), 0) AS rain_24h,
                COALESCE(SUM(CASE WHEN o.observed_at >= :since_1h  THEN o.accumulated_rainfall END), 0) AS rain_1h,
                COALESCE(MAX(CASE WHEN o.observed_at >= :since_24h THEN o.accumulated_rainfall END), 0) AS peak_24h,
                MAX(o.observed_at) AS last_reading
             FROM cemaden_station_link s
             LEFT JOIN cemaden_pluviometric_observation o
                ON o.cemaden_station_link_id = s.id
                AND o.observed_at >= :since_7d
             WHERE $wp",
            $pParams
        )->fetchAssociative() ?: [];

        $hParams = ['since_1h' => $since1h, 'since_24h' => $since24h, 'since_7d' => $since7d];
        $hWhere  = ['h.active = 1'];
        if ($partner !== null) {
            $hWhere[]              = 'h.partner_id = :partner_id';
            $hParams['partner_id'] = $partner->getId();
        }
        $wh = implode(' AND ', $hWhere);

        $hydro = $this->connection->executeQuery(
            "SELECT
                COUNT(DISTINCT h.id) AS stations,
                COALESCE(SUM(o.rain), 0) AS rain_7d,
                COALESCE(SUM(CASE WHEN o.observed_at >= :since_24h THEN o.rain END), 0) AS rain_24h,
                COALESCE(SUM(CASE WHEN o.observed_at >= :since_1h  THEN o.rain END), 0) AS rain_1h,
                COALESCE(MAX(CASE WHEN o.observed_at >= :since_24h THEN o.rain END), 0) AS peak_24h,
                MAX(o.observed_at) AS last_reading
             FROM cemaden_hidro_station_link h
             LEFT JOIN cemaden_hidro_observation o
                ON o.cemaden_hidro_station_link_id = h.id
                AND o.observation_type = 'rain'
                AND o.observed_at >= :since_7d
             WHERE $wh",
            $hParams
        )->fetchAssociative() ?: [];

        $lastReadingRaw = null;
        if (!empty($pluvio['last_reading'])) {
            $lastReadingRaw = (string) $pluvio['last_reading'];
        }
        if (!empty($hydro['last_reading'])
            && ($lastReadingRaw === null || $hydro['last_reading'] > $lastReadingRaw)) {
            $lastReadingRaw = (string) $hydro['last_reading'];
        }

        return [
            'stations'     => (int) (($pluvio['stations'] ?? 0) + ($hydro['stations'] ?? 0)),
            'rain_1h'      => round((float) ($pluvio['rain_1h']  ?? 0) + (float) ($hydro['rain_1h']  ?? 0), 2),
            'rain_24h'     => round((float) ($pluvio['rain_24h'] ?? 0) + (float) ($hydro['rain_24h'] ?? 0), 2),
            'rain_7d'      => round((float) ($pluvio['rain_7d']  ?? 0) + (float) ($hydro['rain_7d']  ?? 0), 2),
            'peak_24h'     => round(max((float) ($pluvio['peak_24h'] ?? 0), (float) ($hydro['peak_24h'] ?? 0)), 2),
            'last_reading' => $this->toUtc($lastReadingRaw),
        ];
    }

    /** @return list<array{time:string,rain:float,wet_stations:int}> */
    private function getPluvioHourly(?Partner $partner, int $hours = 48): array
    {
        $since  = (new \DateTimeImmutable("-{$hours} hours", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $params = ['since_p' => $since, 'since_h' => $since];
        $pWhere = ['o.observed_at >= :since_p'];
        $hWhere = ['o.observed_at >= :since_h', "o.observation_type = 'rain'"];
        if ($partner !== null) {
            $pWhere[]              = 'o.partner_id = :partner_id';
            $hWhere[]              = 'o.partner_id = :partner_id';
            $params['partner_id']  = $partner->getId();
        }
        $wp = implode(' AND ', $pWhere);
        $wh = implode(' AND ', $hWhere);

        $sql = "
            SELECT bucket, SUM(rain) AS rain, SUM(wet) AS wet_stations
            FROM (
                SELECT
                    DATE_FORMAT(DATE_ADD(o.observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    o.accumulated_rainfall AS rain,
                    CASE WHEN o.accumulated_rainfall > 0 THEN 1 ELSE 0 END AS wet
                FROM cemaden_pluviometric_observation o
                WHERE $wp
                UNION ALL
                SELECT
                    DATE_FORMAT(DATE_ADD(o.observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    o.rain AS rain,
                    CASE WHEN o.rain > 0 THEN 1 ELSE 0 END AS wet
                FROM cemaden_hidro_observation o
                WHERE $wh AND o.rain IS NOT NULL
            ) AS u
            GROUP BY bucket
            ORDER BY bucket ASC
            LIMIT 96
        ";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'time'         => (string) $r['bucket'],
            'rain'         => round((float) $r['rain'], 2),
            'wet_stations' => (int) $r['wet_stations'],
        ], $rows);
    }

    /**
     * Série horária de chuva por estação: [stationId => list<point>].
     */
    private function getPluvioHourlyByStation(?Partner $partner, int $hours = 48): array
    {
        $since  = (new \DateTimeImmutable("-{$hours} hours", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $params = ['since_p' => $since, 'since_h' => $since];
        $pWhere = ['o.observed_at >= :since_p'];
        $hWhere = ['o.observed_at >= :since_h', "o.observation_type = 'rain'"];
        if ($partner !== null) {
            $pWhere[]              = 'o.partner_id = :partner_id';
            $hWhere[]              = 'o.partner_id = :partner_id';
            $params['partner_id']  = $partner->getId();
        }
        $wp = implode(' AND ', $pWhere);
        $wh = implode(' AND ', $hWhere);

        $sql = "
            SELECT station_id, bucket, SUM(rain) AS rain, SUM(wet) AS wet FROM (
                SELECT
                    CONCAT('pluvio-', o.cemaden_station_link_id) AS station_id,
                    DATE_FORMAT(DATE_ADD(o.observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    o.accumulated_rainfall AS rain,
                    CASE WHEN o.accumulated_rainfall > 0 THEN 1 ELSE 0 END AS wet
                FROM cemaden_pluviometric_observation o
                WHERE $wp
                UNION ALL
                SELECT
                    CONCAT('hydro-', o.cemaden_hidro_station_link_id) AS station_id,
                    DATE_FORMAT(DATE_ADD(o.observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    o.rain AS rain,
                    CASE WHEN o.rain > 0 THEN 1 ELSE 0 END AS wet
                FROM cemaden_hidro_observation o
                WHERE $wh AND o.rain IS NOT NULL
            ) AS u
            GROUP BY station_id, bucket
            ORDER BY station_id, bucket ASC
        ";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $out = [];
        foreach ($rows as $r) {
            $sid = (string) $r['station_id'];
            $out[$sid][] = [
                'time'         => (string) $r['bucket'],
                'rain'         => round((float) $r['rain'], 2),
                'wet_stations' => (int) $r['wet'],
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function getPluvioByStation(?Partner $partner, int $hours = 24): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since1h  = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $sinceNh  = $now->modify("-{$hours} hours")->format('Y-m-d H:i:s');

        $pParams = ['since_1h' => $since1h, 'since_24h' => $sinceNh];
        $pWhere  = ['s.active = 1'];
        if ($partner !== null) {
            $pWhere[]              = 's.partner_id = :partner_id';
            $pParams['partner_id'] = $partner->getId();
        }
        $wp = implode(' AND ', $pWhere);

        $pluvio = $this->connection->executeQuery(
            "SELECT
                s.id AS station_id,
                s.station_name,
                s.station_code,
                s.city,
                s.state,
                COALESCE(SUM(CASE WHEN o.observed_at >= :since_1h THEN o.accumulated_rainfall END), 0) AS rain_1h,
                COALESCE(SUM(o.accumulated_rainfall), 0) AS rain_24h,
                COALESCE(MAX(o.accumulated_rainfall), 0) AS peak_24h,
                MAX(o.observed_at) AS last_reading
             FROM cemaden_station_link s
             LEFT JOIN cemaden_pluviometric_observation o
                ON o.cemaden_station_link_id = s.id
                AND o.observed_at >= :since_24h
             WHERE $wp
             GROUP BY s.id, s.station_name, s.station_code, s.city, s.state
             HAVING rain_24h > 0 OR last_reading IS NOT NULL",
            $pParams
        )->fetchAllAssociative();

        $hParams = ['since_1h' => $since1h, 'since_24h' => $sinceNh];
        $hWhere  = ['h.active = 1'];
        if ($partner !== null) {
            $hWhere[]              = 'h.partner_id = :partner_id';
            $hParams['partner_id'] = $partner->getId();
        }
        $wh = implode(' AND ', $hWhere);

        $hydro = $this->connection->executeQuery(
            "SELECT
                h.id AS station_id,
                h.station_name,
                h.station_code,
                h.city,
                h.state,
                COALESCE(SUM(CASE WHEN o.observed_at >= :since_1h THEN o.rain END), 0) AS rain_1h,
                COALESCE(SUM(o.rain), 0) AS rain_24h,
                COALESCE(MAX(o.rain), 0) AS peak_24h,
                MAX(o.observed_at) AS last_reading
             FROM cemaden_hidro_station_link h
             LEFT JOIN cemaden_hidro_observation o
                ON o.cemaden_hidro_station_link_id = h.id
                AND o.observation_type = 'rain'
                AND o.observed_at >= :since_24h
             WHERE $wh
             GROUP BY h.id, h.station_name, h.station_code, h.city, h.state
             HAVING rain_24h > 0 OR last_reading IS NOT NULL",
            $hParams
        )->fetchAllAssociative();

        $out = [];
        foreach ($pluvio as $r) {
            $out[] = [
                'stationId'   => 'pluvio-' . (int) $r['station_id'],
                'stationName' => (string) ($r['station_name'] ?? 'Estação'),
                'stationCode' => $r['station_code'],
                'source'      => 'pluvio',
                'city'        => $r['city'],
                'state'       => $r['state'],
                'rain1h'      => round((float) $r['rain_1h'], 2),
                'rain24h'     => round((float) $r['rain_24h'], 2),
                'peak24h'     => round((float) $r['peak_24h'], 2),
                'lastReading' => $this->toUtc($r['last_reading']),
            ];
        }
        foreach ($hydro as $r) {
            $out[] = [
                'stationId'   => 'hydro-' . (int) $r['station_id'],
                'stationName' => (string) ($r['station_name'] ?? 'Estação'),
                'stationCode' => $r['station_code'],
                'source'      => 'hydro',
                'city'        => $r['city'],
                'state'       => $r['state'],
                'rain1h'      => round((float) $r['rain_1h'], 2),
                'rain24h'     => round((float) $r['rain_24h'], 2),
                'peak24h'     => round((float) $r['peak_24h'], 2),
                'lastReading' => $this->toUtc($r['last_reading']),
            ];
        }

        usort($out, static fn ($a, $b) => $b['rain24h'] <=> $a['rain24h']);

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Charts (waze/jams/weather)
    // ─────────────────────────────────────────────────────────────────────

    private function getHourlyActivity(?Partner $partner, array $filters): array
    {
        [$wAlerts, $pAlerts] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true);
        [$wJams,   $pJams]   = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true, ['street', 'city']);

        $alerts = $this->connection->executeQuery(
            "SELECT HOUR(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) AS h, COUNT(*) AS total
             FROM waze_alerts t WHERE $wAlerts
             GROUP BY h",
            $pAlerts
        )->fetchAllKeyValue();

        $jams = $this->connection->executeQuery(
            "SELECT HOUR(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) AS h, COUNT(*) AS total
             FROM waze_jams t WHERE $wJams
             GROUP BY h",
            $pJams
        )->fetchAllKeyValue();

        $points = [];
        for ($h = 0; $h < 24; $h++) {
            $points[] = [
                'hour'   => sprintf('%02d:00', $h),
                'alerts' => (int) ($alerts[$h] ?? 0),
                'jams'   => (int) ($jams[$h] ?? 0),
            ];
        }
        return $points;
    }

    private function getAlertsByType(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true);
        $rows = $this->connection->executeQuery(
            "SELECT t.type AS label, COUNT(*) AS total FROM waze_alerts t WHERE $w GROUP BY t.type ORDER BY total DESC LIMIT 10",
            $p
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'label' => (string) ($r['label'] ?? 'Outro'),
            'total' => (int) $r['total'],
        ], $rows);
    }

    private function getAlertsByCity(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true);
        $rows = $this->connection->executeQuery(
            "SELECT COALESCE(t.city, 'Não informado') AS label, COUNT(*) AS total
             FROM waze_alerts t WHERE $w AND t.city IS NOT NULL AND t.city <> ''
             GROUP BY t.city ORDER BY total DESC LIMIT 10",
            $p
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'label' => (string) $r['label'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    private function getAlertsByStreet(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true);
        $rows = $this->connection->executeQuery(
            "SELECT COALESCE(t.street, 'Não informado') AS label, COUNT(*) AS total
             FROM waze_alerts t WHERE $w AND t.street IS NOT NULL AND t.street <> ''
             GROUP BY t.street ORDER BY total DESC LIMIT 12",
            $p
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'label' => (string) $r['label'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    private function getJamsByLevel(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true, ['street', 'city']);
        $rows = $this->connection->executeQuery(
            "SELECT t.level AS label, COUNT(*) AS total FROM waze_jams t WHERE $w GROUP BY t.level ORDER BY t.level",
            $p
        )->fetchAllAssociative();

        $labels = [0 => 'Sem nível', 1 => 'Baixo', 2 => 'Moderado', 3 => 'Alto', 4 => 'Muito alto', 5 => 'Parado'];

        return array_map(static fn ($r) => [
            'label' => $labels[(int) $r['label']] ?? ('Nível ' . $r['label']),
            'total' => (int) $r['total'],
        ], $rows);
    }

    private function getJamsByHour(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true, ['street', 'city']);

        $rows = $this->connection->executeQuery(
            "SELECT HOUR(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) AS h, COUNT(*) AS total
             FROM waze_jams t WHERE $w
             GROUP BY h",
            $p
        )->fetchAllKeyValue();

        $points = [];
        for ($h = 0; $h < 24; $h++) {
            $points[] = ['hour' => sprintf('%02d:00', $h), 'total' => (int) ($rows[$h] ?? 0)];
        }
        return $points;
    }

    private function getWeatherTrend(?Partner $partner, array $filters): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($partner !== null) {
            $where[]              = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $dateFrom = $this->resolveDateFrom((string) ($filters['period'] ?? 'all'));
        if ($dateFrom !== null) {
            $where[]             = 't.observed_at >= :date_from';
            $params['date_from'] = $dateFrom->format('Y-m-d H:i:s');
        }
        $w = implode(' AND ', $where);

        $rows = $this->connection->executeQuery(
            "SELECT DATE_FORMAT(DATE_ADD(t.observed_at, INTERVAL -3 HOUR), '%Y-%m-%d %H:00:00') AS bucket,
                    AVG(t.temperature) AS temp,
                    AVG(t.relative_humidity) AS humidity,
                    SUM(t.precipitation) AS precipitation
             FROM weather_observation t
             WHERE $w
             GROUP BY bucket
             ORDER BY bucket ASC
             LIMIT 48",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'time'          => (string) $r['bucket'],
            'temperature'   => $r['temp'] !== null ? round((float) $r['temp'], 1) : null,
            'humidity'      => $r['humidity'] !== null ? round((float) $r['humidity'], 1) : null,
            'precipitation' => $r['precipitation'] !== null ? round((float) $r['precipitation'], 2) : 0,
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Map layers
    // ─────────────────────────────────────────────────────────────────────

    public function getMapCenter(?Partner $partner): array
    {
        $params = [];
        $where  = '1=1';
        if ($partner !== null) {
            $where = 'partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }

        $row = $this->connection->executeQuery(
            "SELECT AVG(CAST(latitude AS DECIMAL(10,7))) AS lat,
                    AVG(CAST(longitude AS DECIMAL(10,7))) AS lng
             FROM waze_alerts
             WHERE $where AND latitude IS NOT NULL AND longitude IS NOT NULL",
            $params
        )->fetchAssociative() ?: [];

        return [
            'lat'  => isset($row['lat']) && $row['lat'] !== null ? (float) $row['lat'] : -15.7801,
            'lng'  => isset($row['lng']) && $row['lng'] !== null ? (float) $row['lng'] : -47.9292,
            'zoom' => 11,
        ];
    }

    private function getMapAlerts(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true);
        $rows = $this->connection->executeQuery(
            "SELECT t.id, t.uuid, t.type, t.subtype, t.city, t.street,
                    CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                    CAST(t.longitude AS DECIMAL(10,7)) AS longitude,
                    t.pub_millis, t.collected_at
             FROM waze_alerts t
             WHERE $w AND t.is_active = 1 AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
             ORDER BY t.collected_at DESC
             LIMIT 500",
            $p
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'      => (int) $r['id'],
            'uuid'    => $r['uuid'],
            'type'    => $r['type'],
            'subtype' => $r['subtype'],
            'city'    => $r['city'],
            'street'  => $r['street'],
            'lat'     => (float) $r['latitude'],
            'lng'     => (float) $r['longitude'],
            'when'    => $r['collected_at'],
        ], $rows);
    }

    private function getMapJams(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true, ['street', 'city']);
        $rows = $this->connection->executeQuery(
            "SELECT t.id, t.uuid, t.level, t.street, t.city, t.line, t.delay, t.length, t.speed_kmh
             FROM waze_jams t
             WHERE $w AND t.is_active = 1 AND t.line IS NOT NULL
             ORDER BY t.collected_at DESC
             LIMIT 400",
            $p
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $line = $r['line'];
            if (is_string($line)) {
                $decoded = json_decode($line, true);
                $line    = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($line) || $line === []) continue;

            $normalized = [];
            foreach ($line as $point) {
                if (is_array($point) && isset($point['x'], $point['y'])) {
                    $normalized[] = [(float) $point['y'], (float) $point['x']];
                } elseif (is_array($point) && count($point) >= 2) {
                    $normalized[] = [(float) $point[1], (float) $point[0]];
                }
            }
            if ($normalized === []) continue;

            $out[] = [
                'id'     => (int) $r['id'],
                'uuid'   => $r['uuid'],
                'level'  => (int) $r['level'],
                'street' => $r['street'],
                'city'   => $r['city'],
                'delay'  => (int) $r['delay'],
                'length' => (int) $r['length'],
                'speed'  => (float) $r['speed_kmh'],
                'path'   => $normalized,
            ];
        }
        return $out;
    }

    private function getMapCameras(?Partner $partner): array
    {
        $where  = ['t.is_active = 1'];
        $params = [];
        if ($partner !== null) {
            $where[]              = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $rows = $this->connection->executeQuery(
            "SELECT t.id, t.name, t.city, t.state, t.url, t.url_type, t.provider,
                    CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                    CAST(t.longitude AS DECIMAL(10,7)) AS longitude
             FROM partner_camera_link t
             WHERE $w AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
             LIMIT 500",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'       => (int) $r['id'],
            'name'     => $r['name'],
            'city'     => $r['city'],
            'state'    => $r['state'],
            'url'      => $r['url'],
            'urlType'  => $r['url_type'],
            'provider' => $r['provider'],
            'lat'      => (float) $r['latitude'],
            'lng'      => (float) $r['longitude'],
        ], $rows);
    }

    private function getMapWeatherLocations(?Partner $partner): array
    {
        $where  = ['t.active = 1'];
        $params = [];
        if ($partner !== null) {
            $where[]              = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $rows = $this->connection->executeQuery(
            "SELECT t.id, t.name, t.city, t.state, t.provider,
                    CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                    CAST(t.longitude AS DECIMAL(10,7)) AS longitude
             FROM weather_location t
             WHERE $w AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
             LIMIT 300",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'       => (int) $r['id'],
            'name'     => $r['name'],
            'city'     => $r['city'],
            'state'    => $r['state'],
            'provider' => $r['provider'],
            'lat'      => (float) $r['latitude'],
            'lng'      => (float) $r['longitude'],
        ], $rows);
    }

    private function getMapStations(?Partner $partner): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $params = [
            'since_1h'  => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
            'since_24h' => $now->modify('-24 hours')->format('Y-m-d H:i:s'),
        ];

        $where  = ['t.active = 1'];
        if ($partner !== null) {
            $where[]              = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $rows = $this->connection->executeQuery(
            "SELECT t.id, t.station_name AS name, t.city, t.state, t.station_type,
                    CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                    CAST(t.longitude AS DECIMAL(10,7)) AS longitude,
                    COALESCE(r.rain_1h, 0) AS rain_1h,
                    COALESCE(r.rain_24h, 0) AS rain_24h,
                    r.last_reading
             FROM cemaden_station_link t
             LEFT JOIN (
                SELECT
                    o.cemaden_station_link_id,
                    SUM(CASE WHEN o.observed_at >= :since_1h THEN o.accumulated_rainfall END) AS rain_1h,
                    SUM(o.accumulated_rainfall) AS rain_24h,
                    MAX(o.observed_at) AS last_reading
                FROM cemaden_pluviometric_observation o
                WHERE o.observed_at >= :since_24h
                GROUP BY o.cemaden_station_link_id
             ) r ON r.cemaden_station_link_id = t.id
             WHERE $w AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
             LIMIT 500",
            $params
        )->fetchAllAssociative();

        return array_map(function ($r) {
            return [
                'id'         => (int) $r['id'],
                'name'       => $r['name'],
                'city'       => $r['city'],
                'state'      => $r['state'],
                'type'       => $r['station_type'],
                'lat'        => (float) $r['latitude'],
                'lng'        => (float) $r['longitude'],
                'rain1h'     => round((float) $r['rain_1h'], 2),
                'rain24h'    => round((float) $r['rain_24h'], 2),
                'lastReading'=> $this->toUtc($r['last_reading']),
            ];
        }, $rows);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Recent lists
    // ─────────────────────────────────────────────────────────────────────

    private function getRecentAlerts(?Partner $partner, array $filters, int $limit = 8): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true);

        $rows = $this->connection->executeQuery(
            "SELECT t.id, t.uuid, t.type, t.subtype, t.city, t.street,
                    t.pub_millis, t.collected_at, t.confidence, t.reliability,
                    CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                    CAST(t.longitude AS DECIMAL(10,7)) AS longitude
             FROM waze_alerts t
             WHERE $w AND t.is_active = 1
             ORDER BY t.collected_at DESC
             LIMIT " . (int) $limit,
            $p
        )->fetchAllAssociative();

        return array_map(function ($r) {
            $pub = $r['pub_millis']
                ? new \DateTimeImmutable('@' . intdiv((int) $r['pub_millis'], 1000))
                : null;

            return [
                'id'          => (int) $r['id'],
                'uuid'        => $r['uuid'],
                'type'        => $r['type'],
                'subtype'     => $r['subtype'],
                'city'        => $r['city'],
                'street'      => $r['street'],
                'confidence'  => (int) $r['confidence'],
                'reliability' => (int) $r['reliability'],
                'lat'         => $r['latitude'] !== null ? (float) $r['latitude'] : null,
                'lng'         => $r['longitude'] !== null ? (float) $r['longitude'] : null,
                'pubDateTime' => $pub?->format(DATE_ATOM),
                'collectedAt' => $r['collected_at'],
                'typeLabel'   => $this->labelAlertType((string) $r['type']),
            ];
        }, $rows);
    }

    private function getRecentJams(?Partner $partner, array $filters, int $limit = 8): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true, ['street', 'city']);

        $rows = $this->connection->executeQuery(
            "SELECT t.id, t.uuid, t.street, t.city, t.level, t.delay, t.length, t.speed_kmh, t.collected_at
             FROM waze_jams t
             WHERE $w AND t.is_active = 1
             ORDER BY t.collected_at DESC
             LIMIT " . (int) $limit,
            $p
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'          => (int) $r['id'],
            'uuid'        => $r['uuid'],
            'street'      => $r['street'],
            'city'        => $r['city'],
            'level'       => (int) $r['level'],
            'delay'       => (int) $r['delay'],
            'length'      => (int) $r['length'],
            'speedKmh'    => $r['speed_kmh'] !== null ? (float) $r['speed_kmh'] : null,
            'collectedAt' => $r['collected_at'],
        ], $rows);
    }

    private function getRecentWeather(?Partner $partner, int $limit = 5): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($partner !== null) {
            $where[]              = 'w.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $rows = $this->connection->executeQuery(
            "SELECT w.id, w.temperature, w.relative_humidity, w.precipitation,
                    w.weather_code, w.observed_at,
                    l.name AS location_name, l.city AS location_city
             FROM weather_observation w
             LEFT JOIN weather_location l ON l.id = w.weather_location_id
             WHERE $w
             ORDER BY w.observed_at DESC
             LIMIT " . (int) $limit,
            $params
        )->fetchAllAssociative();

        return array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'locationName'  => $r['location_name'],
                'city'          => $r['location_city'],
                'temperature'   => $r['temperature'] !== null ? (float) $r['temperature'] : null,
                'humidity'      => $r['relative_humidity'] !== null ? (int) $r['relative_humidity'] : null,
                'precipitation' => $r['precipitation'] !== null ? (float) $r['precipitation'] : null,
                'weatherCode'   => $r['weather_code'] !== null ? (int) $r['weather_code'] : null,
                'observedAt'    => $this->toUtc($r['observed_at']),
            ];
        }, $rows);
    }

    public function getRoutesWithLatestSnapshots(?Partner $partner = null, int $limit = 100): array
    {
        $partnerFilter = '';
        $params        = [];
        if ($partner !== null) {
            $partnerFilter        = ' AND r.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }

        $sql = <<<SQL
SELECT
    r.id                AS route_id_internal,
    r.route_id          AS waze_route_id,
    r.name              AS route_name,
    r.from_name,
    r.to_name,
    r.is_active         AS route_active,
    s.id                AS snapshot_id,
    s.name              AS snapshot_name,
    s.city              AS snapshot_city,
    s.state             AS snapshot_state,
    s.waze_route_id     AS snapshot_waze_route_id,
    s.route_id          AS snapshot_route_id,
    s.time              AS current_time_seconds,
    s.historic_time     AS historic_time_seconds,
    s.jam_level,
    s.recorded_at
FROM waze_tvt_route r
INNER JOIN waze_tvt_route_snapshot s
    ON s.id = (
        SELECT latest.id FROM waze_tvt_route_snapshot latest
        WHERE latest.waze_route_id = r.route_id
        ORDER BY latest.recorded_at DESC, latest.id DESC
        LIMIT 1
    )
WHERE r.is_active = 1 {$partnerFilter}
ORDER BY
    CASE
        WHEN s.time IS NOT NULL AND s.historic_time IS NOT NULL
        THEN (s.time - s.historic_time)
        ELSE -1
    END DESC,
    s.recorded_at DESC
LIMIT {$limit}
SQL;

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();
        $routes = [];
        foreach ($rows as $row) {
            $current  = $this->nullableNumber($row['current_time_seconds']);
            $historic = $this->nullableNumber($row['historic_time_seconds']);

            // CORREÇÃO: delay = current - historic (antes estava invertido)
            $delay = ($current !== null && $historic !== null)
                ? max(0, (int) round($current - $historic))
                : null;

            $delayLevel = $this->computeRouteDelayLevel($delay, $historic);

            $routes[] = [
                'id'                    => (int) $row['route_id_internal'],
                'route_id'              => (int) $row['snapshot_route_id'],
                'waze_route_id'         => (string) $row['waze_route_id'],
                'name'                  => $row['snapshot_name'] ?: ($row['route_name'] ?: 'Rota monitorada'),
                'from_name'             => $row['from_name'],
                'to_name'               => $row['to_name'],
                'city'                  => $row['snapshot_city'] ?: 'Local não informado',
                'state'                 => $row['snapshot_state'],
                'status'                => $delay !== null && $delay > 0 ? 'Atrasada' : 'Normal',
                'time'                  => $current,
                'historic_time'         => $historic,
                'delay_seconds'         => $delay,
                'delay_minutes'         => $delay !== null ? round($delay / 60, 1) : null,
                'delay_ratio'           => $delayLevel['ratio'],
                'delay_level'           => [
                    'level' => $delayLevel['level'],
                    'label' => $delayLevel['label'],
                ],
                'jam_level'             => $row['jam_level'],
                'recorded_at'           => $row['recorded_at'],
                'snapshot_id'           => (int) $row['snapshot_id'],
                'snapshot_waze_route_id'=> $row['snapshot_waze_route_id'],
            ];
        }
        return $routes;
    }

    /**
     * Nível de atraso considerando o tempo histórico da rota.
     * Rotas curtas (poucos segundos) toleram menos atraso absoluto.
     */
    private function computeRouteDelayLevel(?int $delaySeconds, ?float $historicSeconds): array
    {
        if ($delaySeconds === null || $delaySeconds <= 0) {
            return ['level' => 'none', 'label' => 'No prazo', 'ratio' => 0.0];
        }

        $historic = $historicSeconds !== null && $historicSeconds > 0 ? $historicSeconds : 60.0;
        $ratio    = $delaySeconds / $historic;

        // referência absoluta: se a rota é curta (<30s), 15s já é significativo
        $ref = min($historic, 30.0);
        $absRatio = $delaySeconds / $ref;

        // ponderação: 70% peso no relativo, 30% no absoluto
        $score = ($ratio * 0.7) + ($absRatio * 0.3);

        if ($score >= 0.60) return ['level' => 'severe',   'label' => 'Crítico',   'ratio' => $ratio];
        if ($score >= 0.30) return ['level' => 'heavy',    'label' => 'Alto',      'ratio' => $ratio];
        if ($score >= 0.12) return ['level' => 'moderate', 'label' => 'Moderado',  'ratio' => $ratio];
        return                    ['level' => 'light',    'label' => 'Leve',      'ratio' => $ratio];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fetch status (última coleta por fonte)
    // ─────────────────────────────────────────────────────────────────────

    private function getPartnerFetchStatus(?Partner $partner): array
    {
        $params = [];
        $pFilter = '';
        if ($partner !== null) {
            $pFilter = ' WHERE partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $status = [
            'waze_alerts'    => null,
            'waze_jams'      => null,
            'waze_tvt'       => null,
            'weather'        => null,
            'cemaden_hydro'  => null,
            'cemaden_pluvio' => null,
            'cameras'        => null,
        ];

        // Partner.fetchAt cobre alerts + jams (mesma coleta) e TVT separado
        if ($partner !== null) {
            $status['waze_alerts'] = $partner->getLastFetchAt();
            $status['waze_jams']   = $partner->getLastFetchAt();
            $status['waze_tvt']    = $partner->getLastTvtFetchAt();
        } else {
            // admin global: usar MAX histórico
            $row = $this->connection->executeQuery(
                "SELECT MAX(last_fetch_at) FROM partner WHERE last_fetch_at IS NOT NULL"
            )->fetchOne();
            $status['waze_alerts'] = $this->toUtc($row);
            $status['waze_jams']   = $this->toUtc($row);

            $rowTvt = $this->connection->executeQuery(
                "SELECT MAX(last_tvt_fetch_at) FROM partner WHERE last_tvt_fetch_at IS NOT NULL"
            )->fetchOne();
            $status['waze_tvt'] = $this->toUtc($rowTvt);
        }

        $wFilter = $partner !== null ? 'WHERE partner_id = :pid' : '';

        // weather
        $status['weather'] = $this->toUtc($this->connection->executeQuery(
            "SELECT MAX(observed_at) FROM weather_observation $wFilter",
            $params
        )->fetchOne());

        // cemaden hydro
        $hFilter = $partner !== null
            ? 'WHERE partner_id = :pid AND active = 1'
            : 'WHERE active = 1';
        $status['cemaden_hydro'] = $this->toUtc($this->connection->executeQuery(
            "SELECT MAX(last_fetched_at) FROM cemaden_hidro_station_link $hFilter",
            $params
        )->fetchOne());

        // cemaden pluvio
        $pFilter2 = $partner !== null
            ? 'WHERE partner_id = :pid AND active = 1'
            : 'WHERE active = 1';
        $status['cemaden_pluvio'] = $this->toUtc($this->connection->executeQuery(
            "SELECT MAX(last_fetched_at) FROM cemaden_station_link $pFilter2",
            $params
        )->fetchOne());

        // cameras
        $cFilter = $partner !== null ? 'WHERE partner_id = :pid' : '';
        $status['cameras'] = $this->toUtc($this->connection->executeQuery(
            "SELECT MAX(created_at) FROM partner_camera_link $cFilter",
            $params
        )->fetchOne());

        return $status;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Health
    // ─────────────────────────────────────────────────────────────────────

    private function computeHealthScore(?Partner $partner, array $filters): int
    {
        $totals = $this->getTotals($partner, $filters);
        $hydro  = $this->getHydroStats($partner);

        $score = 100;

        $score -= min(30, (int) floor($totals['alerts_active'] / 20));
        $score -= min(25, $this->countHighLevelJams($partner, $filters) * 3);
        $score -= min(15, (int) floor($totals['jams_avg_delay'] / 60));

        if ($totals['alerts'] === 0)  $score -= 5;
        if ($totals['jams'] === 0)    $score -= 5;
        if ($totals['weather'] === 0) $score -= 5;

        $score -= min(30, $hydro['risk']['overflow'] * 15);
        $score -= min(20, $hydro['risk']['alert']    * 5);
        $score -= min(10, $hydro['risk']['attention'] * 2);

        return max(0, min(100, $score));
    }

    private function countHighLevelJams(?Partner $partner, array $filters): int
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at', 'partner_id', 'city', true, ['street', 'city']);
        return (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM waze_jams t WHERE $w AND t.is_active = 1 AND t.level >= 3",
            $p
        )->fetchOne();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Utils
    // ─────────────────────────────────────────────────────────────────────

    private function nullableNumber(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }

    private function toUtc(\Stringable|string|\DateTimeInterface|null $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof \DateTimeImmutable) return $value;
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        try {
            return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
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
