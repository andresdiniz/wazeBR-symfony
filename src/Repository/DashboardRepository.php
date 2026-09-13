<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenStationLink;
use App\Entity\Partner;
use App\Entity\PartnerCameraLink;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Entity\WazeJam;
use App\Entity\WeatherLocation;
use App\Entity\WeatherObservation;
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

    /**
     * @param array{period?:string,type?:string,query?:string,city?:?string} $filters
     * @return array<string,mixed>
     */
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
            'health_score' => $this->computeHealthScore($partner, $filters),
            'updated_at'   => new \DateTimeImmutable(),
            'filters'      => $filters,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Filters helpers
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    private function normalizeFilters(array $filters): array
    {
        return [
            'period' => in_array($filters['period'] ?? 'all', ['all','today','week','month','year'], true)
                ? (string) $filters['period']
                : 'all',
            'type'   => in_array($filters['type'] ?? 'all', ['all','alerts','jams','weather','routes'], true)
                ? (string) $filters['type']
                : 'all',
            'query'  => trim((string) ($filters['query'] ?? '')),
            'city'   => $filters['city'] ? trim((string) $filters['city']) : null,
        ];
    }

    private function resolveDateFrom(string $period): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return match ($period) {
            'today' => $now->setTime(0, 0),
            'week'  => $now->modify('-7 days'),
            'month' => $now->modify('-30 days'),
            'year'  => $now->modify('-365 days'),
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>,2:array<string,int>}
     */
    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>,2:array<string,int>}
     */
    private function buildWhere(
        ?Partner $partner,
        array $filters,
        string $dateColumn,
        string $partnerColumn = 'partner_id',
        string $cityColumn = 'city'
    ): array {
        // Normaliza defensivamente — a chamada pode vir sem todas as chaves.
        $filters = [
            'period' => $filters['period'] ?? 'all',
            'query'  => $filters['query']  ?? '',
            'city'   => $filters['city']   ?? null,
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

        if (!empty($filters['query'])) {
            $where[]     = "(t.type LIKE :q OR t.subtype LIKE :q OR t.city LIKE :q OR t.street LIKE :q)";
            $params['q'] = '%' . $filters['query'] . '%';
        }

        return [implode(' AND ', $where), $params, $types];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Totals & KPIs
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    private function getTotals(?Partner $partner, array $filters): array
    {
        [$wAlerts, $pAlerts]   = $this->buildWhere($partner, $filters, 'collected_at');
        [$wJams,   $pJams]     = $this->buildWhere($partner, $filters, 'collected_at');
        [$wWeather,$pWeather]  = $this->buildWhere($partner, $filters, 'observed_at', 'partner_id', '');
        [$wCams,   $pCams]     = $this->buildWhere($partner, [], 'created_at');
        [$wStations, $pStations] = $this->buildWhere($partner, [], 'created_at');

        $sqlAlerts = "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) AS active
                      FROM waze_alerts t WHERE $wAlerts";

        $sqlJams = "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) AS active,
                        AVG(t.delay) AS avg_delay,
                        AVG(t.level) AS avg_level
                    FROM waze_jams t WHERE $wJams";

        $sqlWeather = "SELECT COUNT(*) AS total
                       FROM weather_observation t WHERE $wWeather";

        $sqlCams = "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) AS active
                    FROM partner_camera_link t WHERE $wCams";

        $sqlStations = "SELECT COUNT(*) AS total
                        FROM cemaden_station_link t WHERE $wStations";

        $alerts  = $this->connection->executeQuery($sqlAlerts, $pAlerts)->fetchAssociative() ?: [];
        $jams    = $this->connection->executeQuery($sqlJams, $pJams)->fetchAssociative() ?: [];
        $weather = $this->connection->executeQuery($sqlWeather, $pWeather)->fetchAssociative() ?: [];
        $cams    = $this->connection->executeQuery($sqlCams, $pCams)->fetchAssociative() ?: [];
        $stations= $this->connection->executeQuery($sqlStations, $pStations)->fetchAssociative() ?: [];

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

        $healthScore = $this->computeHealthScore($partner, $filters);

        return [
            'alerts'         => $totals['alerts'],
            'alerts_active'  => $totals['alerts_active'],
            'jams'           => $totals['jams'],
            'jams_active'    => $totals['jams_active'],
            'weather'        => $totals['weather'],
            'cameras'        => $totals['cameras'],
            'stations'       => $totals['stations'],
            'partners'       => $totals['partners'],
            'users'          => $totals['users'],
            'health_score'   => $healthScore,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Charts
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    private function getHourlyActivity(?Partner $partner, array $filters): array
    {
        [$wAlerts, $pAlerts] = $this->buildWhere($partner, $filters, 'collected_at');
        [$wJams,   $pJams]   = $this->buildWhere($partner, $filters, 'collected_at');

        $sqlAlerts = "SELECT HOUR(t.collected_at) AS h, COUNT(*) AS total
                      FROM waze_alerts t WHERE $wAlerts
                      GROUP BY HOUR(t.collected_at)";
        $sqlJams   = "SELECT HOUR(t.collected_at) AS h, COUNT(*) AS total
                      FROM waze_jams t WHERE $wJams
                      GROUP BY HOUR(t.collected_at)";

        $alerts = $this->connection->executeQuery($sqlAlerts, $pAlerts)->fetchAllKeyValue();
        $jams   = $this->connection->executeQuery($sqlJams, $pJams)->fetchAllKeyValue();

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

    /** @param array<string,mixed> $filters */
    private function getAlertsByType(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at');
        $sql = "SELECT t.type AS label, COUNT(*) AS total
                FROM waze_alerts t WHERE $w
                GROUP BY t.type ORDER BY total DESC LIMIT 10";

        $rows = $this->connection->executeQuery($sql, $p)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'label' => (string) ($r['label'] ?? 'Outro'),
            'total' => (int) $r['total'],
        ], $rows);
    }

    /** @param array<string,mixed> $filters */
    private function getAlertsByCity(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at');
        $sql = "SELECT COALESCE(t.city, 'Não informado') AS label, COUNT(*) AS total
                FROM waze_alerts t WHERE $w AND t.city IS NOT NULL AND t.city <> ''
                GROUP BY t.city ORDER BY total DESC LIMIT 10";

        $rows = $this->connection->executeQuery($sql, $p)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'label' => (string) $r['label'],
            'total' => (int) $r['total'],
        ], $rows);
    }

    /** @param array<string,mixed> $filters */
    private function getJamsByLevel(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at');
        $sql = "SELECT t.level AS label, COUNT(*) AS total
                FROM waze_jams t WHERE $w
                GROUP BY t.level ORDER BY t.level";

        $rows = $this->connection->executeQuery($sql, $p)->fetchAllAssociative();
        $labels = [0 => 'Sem nível', 1 => 'Baixo', 2 => 'Moderado', 3 => 'Alto', 4 => 'Muito alto', 5 => 'Parado'];

        return array_map(static fn ($r) => [
            'label' => $labels[(int) $r['label']] ?? ('Nível ' . $r['label']),
            'total' => (int) $r['total'],
        ], $rows);
    }

    /** @param array<string,mixed> $filters */
    private function getJamsByHour(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at');
        $sql = "SELECT HOUR(t.collected_at) AS h, COUNT(*) AS total
                FROM waze_jams t WHERE $w
                GROUP BY HOUR(t.collected_at)";
        $rows = $this->connection->executeQuery($sql, $p)->fetchAllKeyValue();
        $points = [];
        for ($h = 0; $h < 24; $h++) {
            $points[] = ['hour' => sprintf('%02d:00', $h), 'total' => (int) ($rows[$h] ?? 0)];
        }
        return $points;
    }

    /** @param array<string,mixed> $filters */
    private function getWeatherTrend(?Partner $partner, array $filters): array
    {
        $where = ['1=1'];
        $params = [];
        if ($partner !== null) {
            $where[] = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $dateFrom = $this->resolveDateFrom((string) $filters['period']);
        if ($dateFrom !== null) {
            $where[] = 't.observed_at >= :date_from';
            $params['date_from'] = $dateFrom->format('Y-m-d H:i:s');
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT DATE_FORMAT(t.observed_at, '%Y-%m-%d %H:00:00') AS bucket,
                       AVG(t.temperature) AS temp,
                       AVG(t.relative_humidity) AS humidity,
                       SUM(t.precipitation) AS precipitation
                FROM weather_observation t
                WHERE $w
                GROUP BY bucket
                ORDER BY bucket ASC
                LIMIT 48";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

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

        $sql = "SELECT
                    AVG(CAST(latitude AS DECIMAL(10,7))) AS lat,
                    AVG(CAST(longitude AS DECIMAL(10,7))) AS lng
                FROM waze_alerts
                WHERE $where AND latitude IS NOT NULL AND longitude IS NOT NULL";

        $row = $this->connection->executeQuery($sql, $params)->fetchAssociative() ?: [];

        return [
            'lat' => isset($row['lat']) && $row['lat'] !== null ? (float) $row['lat'] : -15.7801,
            'lng' => isset($row['lng']) && $row['lng'] !== null ? (float) $row['lng'] : -47.9292,
            'zoom' => 11,
        ];
    }

    /** @param array<string,mixed> $filters */
    private function getMapAlerts(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at');
        $sql = "SELECT t.id, t.uuid, t.type, t.subtype, t.city, t.street,
                       CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                       CAST(t.longitude AS DECIMAL(10,7)) AS longitude,
                       t.pub_millis, t.collected_at
                FROM waze_alerts t
                WHERE $w
                  AND t.is_active = 1
                  AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
                ORDER BY t.collected_at DESC
                LIMIT 500";

        $rows = $this->connection->executeQuery($sql, $p)->fetchAllAssociative();

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

    /** @param array<string,mixed> $filters */
    private function getMapJams(?Partner $partner, array $filters): array
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at');
        $sql = "SELECT t.id, t.uuid, t.level, t.street, t.city, t.line, t.delay, t.length, t.speed_kmh
                FROM waze_jams t
                WHERE $w AND t.is_active = 1 AND t.line IS NOT NULL
                ORDER BY t.collected_at DESC
                LIMIT 400";

        $rows = $this->connection->executeQuery($sql, $p)->fetchAllAssociative();
        $out  = [];

        foreach ($rows as $r) {
            $line = $r['line'];
            if (is_string($line)) {
                $decoded = json_decode($line, true);
                $line    = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($line) || $line === []) {
                continue;
            }
            $normalized = [];
            foreach ($line as $point) {
                if (is_array($point) && isset($point['x'], $point['y'])) {
                    $normalized[] = [(float) $point['y'], (float) $point['x']]; // [lat, lng]
                } elseif (is_array($point) && count($point) >= 2) {
                    $normalized[] = [(float) $point[1], (float) $point[0]];
                }
            }
            if ($normalized === []) {
                continue;
            }

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
            $where[] = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT t.id, t.name, t.city, t.state, t.url, t.url_type, t.provider,
                       CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                       CAST(t.longitude AS DECIMAL(10,7)) AS longitude
                FROM partner_camera_link t
                WHERE $w AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
                LIMIT 500";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

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
            $where[] = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT t.id, t.name, t.city, t.state, t.provider,
                       CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                       CAST(t.longitude AS DECIMAL(10,7)) AS longitude
                FROM weather_location t
                WHERE $w AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
                LIMIT 300";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

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
        $where  = ['t.active = 1'];
        $params = [];
        if ($partner !== null) {
            $where[] = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT t.id, t.station_name AS name, t.city, t.state, t.station_type,
                       CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                       CAST(t.longitude AS DECIMAL(10,7)) AS longitude
                FROM cemaden_station_link t
                WHERE $w AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
                LIMIT 500";

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'    => (int) $r['id'],
            'name'  => $r['name'],
            'city'  => $r['city'],
            'state' => $r['state'],
            'type'  => $r['station_type'],
            'lat'   => (float) $r['latitude'],
            'lng'   => (float) $r['longitude'],
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Recent lists
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    private function getRecentAlerts(?Partner $partner, array $filters, int $limit = 8): array
    {
        $where = ['t.is_active = 1'];
        $params = [];
        if ($partner !== null) {
            $where[] = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $dateFrom = $this->resolveDateFrom((string) $filters['period']);
        if ($dateFrom !== null) {
            $where[] = 't.collected_at >= :date_from';
            $params['date_from'] = $dateFrom->format('Y-m-d H:i:s');
        }
        if (!empty($filters['query'])) {
            $where[] = '(t.type LIKE :q OR t.subtype LIKE :q OR t.city LIKE :q OR t.street LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }
        if (!empty($filters['city'])) {
            $where[] = 't.city = :city';
            $params['city'] = $filters['city'];
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT t.id, t.uuid, t.type, t.subtype, t.city, t.street,
                       t.pub_millis, t.collected_at, t.confidence, t.reliability,
                       CAST(t.latitude AS DECIMAL(10,7)) AS latitude,
                       CAST(t.longitude AS DECIMAL(10,7)) AS longitude
                FROM waze_alerts t
                WHERE $w
                ORDER BY t.collected_at DESC
                LIMIT " . (int) $limit;

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(function ($r) {
            $pub = $r['pub_millis'] ? (new \DateTimeImmutable())->setTimestamp((int) floor(((int) $r['pub_millis']) / 1000)) : null;
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

    /** @param array<string,mixed> $filters */
    private function getRecentJams(?Partner $partner, array $filters, int $limit = 8): array
    {
        $where = ['t.is_active = 1'];
        $params = [];
        if ($partner !== null) {
            $where[] = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $dateFrom = $this->resolveDateFrom((string) $filters['period']);
        if ($dateFrom !== null) {
            $where[] = 't.collected_at >= :date_from';
            $params['date_from'] = $dateFrom->format('Y-m-d H:i:s');
        }
        if (!empty($filters['query'])) {
            $where[] = '(t.street LIKE :q OR t.city LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }
        if (!empty($filters['city'])) {
            $where[] = 't.city = :city';
            $params['city'] = $filters['city'];
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT t.id, t.uuid, t.street, t.city, t.level, t.delay, t.length, t.speed_kmh, t.collected_at
                FROM waze_jams t
                WHERE $w
                ORDER BY t.collected_at DESC
                LIMIT " . (int) $limit;

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'      => (int) $r['id'],
            'uuid'    => $r['uuid'],
            'street'  => $r['street'],
            'city'    => $r['city'],
            'level'   => (int) $r['level'],
            'delay'   => (int) $r['delay'],
            'length'  => (int) $r['length'],
            'speedKmh'=> $r['speed_kmh'] !== null ? (float) $r['speed_kmh'] : null,
            'collectedAt' => $r['collected_at'],
        ], $rows);
    }

    private function getRecentWeather(?Partner $partner, int $limit = 5): array
    {
        $where = ['1=1'];
        $params = [];
        if ($partner !== null) {
            $where[] = 'w.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }
        $w = implode(' AND ', $where);

        $sql = "SELECT w.id, w.temperature, w.relative_humidity, w.precipitation,
                       w.weather_code, w.observed_at,
                       l.name AS location_name, l.city AS location_city
                FROM weather_observation w
                LEFT JOIN weather_location l ON l.id = w.weather_location_id
                WHERE $w
                ORDER BY w.observed_at DESC
                LIMIT " . (int) $limit;

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'           => (int) $r['id'],
            'locationName' => $r['location_name'],
            'city'         => $r['location_city'],
            'temperature'  => $r['temperature'] !== null ? (float) $r['temperature'] : null,
            'humidity'     => $r['relative_humidity'] !== null ? (int) $r['relative_humidity'] : null,
            'precipitation'=> $r['precipitation'] !== null ? (float) $r['precipitation'] : null,
            'weatherCode'  => $r['weather_code'] !== null ? (int) $r['weather_code'] : null,
            'observedAt'   => $r['observed_at'],
        ], $rows);
    }

    /**
     * Rotas TVT com último snapshot — mesma query original, agora com escopo opcional de partner.
     *
     * @return list<array<string,mixed>>
     */
    public function getRoutesWithLatestSnapshots(?Partner $partner = null, int $limit = 100): array
    {
        $partnerFilter = '';
        $params        = [];
        if ($partner !== null) {
            $partnerFilter       = ' AND r.partner_id = :partner_id';
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
        SELECT latest.id
        FROM waze_tvt_route_snapshot latest
        WHERE latest.waze_route_id = r.route_id
        ORDER BY latest.recorded_at DESC, latest.id DESC
        LIMIT 1
    )
WHERE r.is_active = 1 {$partnerFilter}
ORDER BY
    CASE
        WHEN s.time IS NOT NULL AND s.historic_time IS NOT NULL
        THEN (s.historic_time - s.time)
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
            $delay    = $current !== null && $historic !== null ? max(0, $historic - $current) : null;

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
                'jam_level'             => $row['jam_level'],
                'recorded_at'           => $row['recorded_at'],
                'snapshot_id'           => (int) $row['snapshot_id'],
                'snapshot_waze_route_id'=> $row['snapshot_waze_route_id'],
            ];
        }

        return $routes;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Health
    // ─────────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $filters */
    private function computeHealthScore(?Partner $partner, array $filters): int
    {
        $totals = $this->getTotals($partner, $filters);
        $score  = 100;

        // Alertas ativos pesam
        $score -= min(30, (int) floor($totals['alerts_active'] / 20));

        // Jams nível alto
        $highJams = $this->countHighLevelJams($partner, $filters);
        $score -= min(25, $highJams * 3);

        // Delay médio
        $score -= min(15, (int) floor($totals['jams_avg_delay'] / 60));

        // Fontes sem dados
        if ($totals['alerts'] === 0)  $score -= 5;
        if ($totals['jams'] === 0)    $score -= 5;
        if ($totals['weather'] === 0) $score -= 5;

        return max(0, min(100, $score));
    }

    /** @param array<string,mixed> $filters */
    private function countHighLevelJams(?Partner $partner, array $filters): int
    {
        [$w, $p] = $this->buildWhere($partner, $filters, 'collected_at');
        $sql = "SELECT COUNT(*) FROM waze_jams t WHERE $w AND t.is_active = 1 AND t.level >= 3";
        return (int) $this->connection->executeQuery($sql, $p)->fetchOne();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Utils
    // ─────────────────────────────────────────────────────────────────────

    private function nullableNumber(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }

    private function labelAlertType(string $type): string
    {
        return match (strtoupper($type)) {
            'HAZARD'           => 'Perigo',
            'ROAD_CLOSED'      => 'Via fechada',
            'ACCIDENT'         => 'Acidente',
            'JAM'              => 'Congestionamento',
            'POLICE'           => 'Polícia',
            'WEATHERHAZARD'    => 'Perigo climático',
            'CONSTRUCTION'     => 'Obras',
            default            => ucfirst(strtolower($type)) ?: 'Alerta',
        };
    }
}
