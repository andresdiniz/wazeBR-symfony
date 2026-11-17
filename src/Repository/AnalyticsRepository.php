<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AnalyticsRepository
{
    private Connection $connection;

    public function __construct(
        ManagerRegistry $registry,
        private readonly CacheInterface $cache
    ) {
        $this->connection = $registry->getConnection();
    }

    /**
     * Retorna apenas dados agregados para o painel analítico.
     *
     * @param array{days:int,city:string|null} $filters
     * @return array<string,mixed>
     */
    public function getAnalytics(?Partner $partner, array $filters): array
    {
        $days = $filters['days'];
        $city = $filters['city'];

        $cacheKey = sprintf(
            'analytics_stats_%s_%d_%s',
            $partner?->getId() ?? 'all',
            $days,
            $city ?? 'all'
        );

        return $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($partner, $days, $city): array {
                $item->expiresAfter(30);

                return $this->buildAnalytics($partner, $days, $city);
            }
        );
    }

    private function buildAnalytics(?Partner $partner, int $days, ?string $city): array
    {
        $current = $this->periodRange($days, 0);
        $previous = $this->periodRange($days, $days);

        $currentKpis = $this->getKpis($partner, $city, $current['from'], $current['to']);
        $previousKpis = $this->getKpis($partner, $city, $previous['from'], $previous['to']);

        return [
            'period' => [
                'days' => $days,
                'from' => $current['from'],
                'to' => $current['to'],
                'previous_from' => $previous['from'],
                'previous_to' => $previous['to'],
            ],
            'kpis' => [
                'alerts' => $this->withChange(
                    $currentKpis['alerts'],
                    $previousKpis['alerts']
                ),
                'jams' => $this->withChange(
                    $currentKpis['jams'],
                    $previousKpis['jams']
                ),
                'average_delay' => $this->withChange(
                    $currentKpis['average_delay'],
                    $previousKpis['average_delay']
                ),
                'weather_observations' => $this->withChange(
                    $currentKpis['weather_observations'],
                    $previousKpis['weather_observations']
                ),
                'hydro_risk' => $this->withChange(
                    $currentKpis['hydro_risk'],
                    $previousKpis['hydro_risk']
                ),
            ],
            'daily' => $this->getDailySeries($partner, $city, $current['from'], $current['to']),
            'top_streets' => $this->getTopStreets($partner, $city, $current['from'], $current['to']),
            'heatmap' => $this->getHeatmap($partner, $city, $current['from'], $current['to']),
            'hydro' => $this->getHydroSummary($partner),
            'generated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'filters' => [
                'days' => $days,
                'city' => $city,
            ],
        ];
    }

    /**
     * @return array{from:string,to:string}
     */
    private function periodRange(int $days, int $offsetDays): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $to = $now->modify(sprintf('-%d days', $offsetDays));
        $from = $to->modify(sprintf('-%d days', $days));

        return [
            'from' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'to' => $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{alerts:int,jams:int,average_delay:float,weather_observations:int,hydro_risk:int}
     */
    private function getKpis(?Partner $partner, ?string $city, string $from, string $to): array
    {
        [$alertWhere, $alertParams] = $this->trafficWhere(
            $partner,
            $city,
            $from,
            $to
        );

        [$jamWhere, $jamParams] = $this->trafficWhere(
            $partner,
            $city,
            $from,
            $to
        );

        [$weatherWhere, $weatherParams] = $this->weatherWhere(
            $partner,
            $from,
            $to
        );

        $alerts = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM waze_alerts t WHERE {$alertWhere}",
            $alertParams
        )->fetchOne();

        $jams = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM waze_jams t WHERE {$jamWhere}",
            $jamParams
        )->fetchOne();

        $averageDelay = (float) ($this->connection->executeQuery(
            "SELECT AVG(t.delay) FROM waze_jams t WHERE {$jamWhere}",
            $jamParams
        )->fetchOne() ?? 0);

        $weatherObservations = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM weather_observation t WHERE {$weatherWhere}",
            $weatherParams
        )->fetchOne();

        return [
            'alerts' => $alerts,
            'jams' => $jams,
            'average_delay' => round($averageDelay, 2),
            'weather_observations' => $weatherObservations,
            'hydro_risk' => $this->getHydroRiskTotal($partner),
        ];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function trafficWhere(
        ?Partner $partner,
        ?string $city,
        string $from,
        string $to
    ): array {
        $where = [
            't.collected_at >= :date_from',
            't.collected_at < :date_to',
        ];

        $params = [
            'date_from' => $from,
            'date_to' => $to,
        ];

        if ($partner !== null) {
            $where[] = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }

        if ($city !== null && $city !== '') {
            $where[] = 't.city = :city';
            $params['city'] = $city;
        }

        return [
            implode(' AND ', $where),
            $params,
        ];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function weatherWhere(
        ?Partner $partner,
        string $from,
        string $to
    ): array {
        $where = [
            't.observed_at >= :date_from',
            't.observed_at < :date_to',
        ];

        $params = [
            'date_from' => $from,
            'date_to' => $to,
        ];

        if ($partner !== null) {
            $where[] = 't.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }

        return [
            implode(' AND ', $where),
            $params,
        ];
    }

    /**
     * @return list<array{date:string,alerts:int,jams:int}>
     */
    private function getDailySeries(
        ?Partner $partner,
        ?string $city,
        string $from,
        string $to
    ): array {
        [$where, $params] = $this->trafficWhere($partner, $city, $from, $to);

        $alerts = $this->connection->executeQuery(
            "SELECT
                DATE_FORMAT(DATE_ADD(t.collected_at, INTERVAL -3 HOUR), '%Y-%m-%d') AS date,
                COUNT(*) AS total
             FROM waze_alerts t
             WHERE {$where}
             GROUP BY date
             ORDER BY date ASC",
            $params
        )->fetchAllKeyValue();

        $jams = $this->connection->executeQuery(
            "SELECT
                DATE_FORMAT(DATE_ADD(t.collected_at, INTERVAL -3 HOUR), '%Y-%m-%d') AS date,
                COUNT(*) AS total
             FROM waze_jams t
             WHERE {$where}
             GROUP BY date
             ORDER BY date ASC",
            $params
        )->fetchAllKeyValue();

        $start = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));
        $series = [];

        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');

            $series[] = [
                'date' => $key,
                'alerts' => (int) ($alerts[$key] ?? 0),
                'jams' => (int) ($jams[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @return list<array{street:string,alerts:int,jams:int,total:int}>
     */
    private function getTopStreets(
        ?Partner $partner,
        ?string $city,
        string $from,
        string $to
    ): array {
        [$where, $params] = $this->trafficWhere($partner, $city, $from, $to);

        $alerts = $this->connection->executeQuery(
            "SELECT
                COALESCE(NULLIF(t.street, ''), 'Não informada') AS street,
                COUNT(*) AS total
             FROM waze_alerts t
             WHERE {$where}
             GROUP BY street
             ORDER BY total DESC
             LIMIT 10",
            $params
        )->fetchAllKeyValue();

        $jams = $this->connection->executeQuery(
            "SELECT
                COALESCE(NULLIF(t.street, ''), 'Não informada') AS street,
                COUNT(*) AS total
             FROM waze_jams t
             WHERE {$where}
             GROUP BY street
             ORDER BY total DESC
             LIMIT 10",
            $params
        )->fetchAllKeyValue();

        $streets = array_unique(array_merge(
            array_keys($alerts),
            array_keys($jams)
        ));

        $result = [];

        foreach ($streets as $street) {
            $alertTotal = (int) ($alerts[$street] ?? 0);
            $jamTotal = (int) ($jams[$street] ?? 0);

            $result[] = [
                'street' => $street,
                'alerts' => $alertTotal,
                'jams' => $jamTotal,
                'total' => $alertTotal + $jamTotal,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return array_slice($result, 0, 10);
    }

    /**
     * @return array<string,array<string,int>>
     */
    private function getHeatmap(
        ?Partner $partner,
        ?string $city,
        string $from,
        string $to
    ): array {
        [$where, $params] = $this->trafficWhere($partner, $city, $from, $to);

        $rows = $this->connection->executeQuery(
            "SELECT
                DAYOFWEEK(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) AS weekday,
                CASE
                    WHEN HOUR(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) < 6 THEN '00-06'
                    WHEN HOUR(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) < 9 THEN '06-09'
                    WHEN HOUR(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) < 12 THEN '09-12'
                    WHEN HOUR(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) < 15 THEN '12-15'
                    WHEN HOUR(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) < 18 THEN '15-18'
                    WHEN HOUR(DATE_ADD(t.collected_at, INTERVAL -3 HOUR)) < 21 THEN '18-21'
                    ELSE '21-24'
                END AS bucket,
                COUNT(*) AS total
             FROM waze_alerts t
             WHERE {$where}
             GROUP BY weekday, bucket",
            $params
        )->fetchAllAssociative();

        $heatmap = [];

        foreach ($rows as $row) {
            $weekday = (int) $row['weekday'];
            $bucket = (string) $row['bucket'];

            $heatmap[$weekday][$bucket] = (int) $row['total'];
        }

        return $heatmap;
    }

    private function getHydroRiskTotal(?Partner $partner): int
    {
        $params = [];
        $where = ['h.active = 1'];

        if ($partner !== null) {
            $where[] = 'h.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }

        $sql = "SELECT COUNT(*) FROM (
            SELECT
                CASE
                    WHEN o.water_level IS NULL THEN 'unknown'
                    WHEN o.cota_transbordamento IS NOT NULL AND o.water_level >= o.cota_transbordamento THEN 'overflow'
                    WHEN o.cota_alerta IS NOT NULL AND o.water_level >= o.cota_alerta THEN 'alert'
                    WHEN o.cota_atencao IS NOT NULL AND o.water_level >= o.cota_atencao THEN 'attention'
                    ELSE 'normal'
                END AS risk
            FROM cemaden_hidro_station_link h
            LEFT JOIN cemaden_hidro_observation o
                ON o.cemaden_hidro_station_link_id = h.id
                AND o.observation_type = 'level'
                AND o.id = (
                    SELECT o2.id
                    FROM cemaden_hidro_observation o2
                    WHERE o2.cemaden_hidro_station_link_id = h.id
                      AND o2.observation_type = 'level'
                    ORDER BY o2.observed_at DESC, o2.id DESC
                    LIMIT 1
                )
            WHERE " . implode(' AND ', $where) . "
        ) AS risks
        WHERE risks.risk IN ('attention', 'alert', 'overflow')";

        return (int) $this->connection->executeQuery($sql, $params)->fetchOne();
    }

    /**
     * @return array{stations:int,attention:int,alert:int,overflow:int}
     */
    private function getHydroSummary(?Partner $partner): array
    {
        $params = [];
        $where = ['h.active = 1'];

        if ($partner !== null) {
            $where[] = 'h.partner_id = :partner_id';
            $params['partner_id'] = $partner->getId();
        }

        $stations = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM cemaden_hidro_station_link h WHERE " . implode(' AND ', $where),
            $params
        )->fetchOne();

        return [
            'stations' => $stations,
            'attention' => $this->getHydroRiskTotal($partner),
            'alert' => 0,
            'overflow' => 0,
        ];
    }

    /**
     * @return array{value:int|float,change:float|null,direction:string}
     */
    private function withChange(int|float $current, int|float $previous): array
    {
        if ($previous == 0) {
            return [
                'value' => $current,
                'change' => null,
                'direction' => $current > 0 ? 'up' : 'flat',
            ];
        }

        $change = (($current - $previous) / $previous) * 100;

        return [
            'value' => $current,
            'change' => round($change, 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
        ];
    }
}
