<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Entity\WazeJam;
use App\Entity\WeatherObservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

final class DashboardRepository extends ServiceEntityRepository
{
    private Connection $connection;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
        $this->connection = $registry->getConnection();
    }

    /** @return array<string, mixed> */
    public function getDashboardStats(): array
    {
        $em = $this->getEntityManager();
        $alertRepository = $em->getRepository(WazeAlert::class);
        $jamRepository = $em->getRepository(WazeJam::class);
        $weatherRepository = $em->getRepository(WeatherObservation::class);
        $recentAlerts = $alertRepository->findBy([], ['id' => 'DESC'], 8);
        $recentJams = $jamRepository->findBy([], ['id' => 'DESC'], 8);
        $recentWeather = $weatherRepository->findBy([], ['id' => 'DESC'], 5);

        return [
            'total_alerts' => $alertRepository->count([]),
            'total_jams' => $jamRepository->count([]),
            'total_weather' => $weatherRepository->count([]),
            'total_partners' => $em->getRepository(Partner::class)->count([]),
            'total_users' => $em->getRepository(User::class)->count([]),
            'recent_alerts' => $recentAlerts,
            'recent_jams' => $recentJams,
            'recent_weather' => $recentWeather,
            'recent_routes' => $this->findRoutesWithLatestSnapshots(),
            'alerts_by_type' => $this->groupAlertsByType($recentAlerts),
            'updated_at' => new \DateTimeImmutable(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function findRoutesWithLatestSnapshots(): array
    {
        $sql = <<<'SQL'
SELECT
    r.id AS route_id_internal,
    r.route_id AS waze_route_id,
    r.name AS route_name,
    r.from_name,
    r.to_name,
    NULL AS route_city,
    r.is_active AS route_active,
    s.id AS snapshot_id,
    s.name AS snapshot_name,
    s.city AS snapshot_city,
    s.state AS snapshot_state,
    s.waze_route_id AS snapshot_waze_route_id,
    s.route_id AS snapshot_route_id,
    s.time AS current_time_seconds,
    s.historic_time AS historic_time_seconds,
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
WHERE r.is_active = 1
ORDER BY
    CASE
        WHEN s.time IS NOT NULL AND s.historic_time IS NOT NULL
        THEN (s.historic_time - s.time)
        ELSE -1
    END DESC,
    s.recorded_at DESC
LIMIT 100
SQL;

        $rows = $this->connection->executeQuery($sql)->fetchAllAssociative();
        $routes = [];
        foreach ($rows as $row) {
            $current = $this->nullableNumber($row['current_time_seconds']);
            $historic = $this->nullableNumber($row['historic_time_seconds']);
            $delaySeconds = $current !== null && $historic !== null ? max(0, $historic - $current) : null;
            $routes[] = [
                'id' => (int) $row['route_id_internal'],
                'route_id' => (int) $row['snapshot_route_id'],
                'waze_route_id' => (string) $row['waze_route_id'],
                'name' => $row['snapshot_name'] ?: ($row['route_name'] ?: 'Rota monitorada'),
                'from_name' => $row['from_name'],
                'to_name' => $row['to_name'],
                'city' => $row['snapshot_city'] ?: 'Local não informado',
                'state' => $row['snapshot_state'],
                'status' => $delaySeconds !== null && $delaySeconds > 0 ? 'Atrasada' : 'Normal',
                'time' => $current,
                'historic_time' => $historic,
                'delay_seconds' => $delaySeconds,
                'delay_minutes' => $delaySeconds !== null ? round($delaySeconds / 60, 1) : null,
                'jam_level' => $row['jam_level'],
                'recorded_at' => $row['recorded_at'],
                'snapshot_id' => (int) $row['snapshot_id'],
                'snapshot_waze_route_id' => $row['snapshot_waze_route_id'],
            ];
        }
        return $routes;
    }

    /** @param list<object> $alerts */
    private function groupAlertsByType(array $alerts): array
    {
        $grouped = [];
        foreach ($alerts as $alert) {
            $type = method_exists($alert, 'getType') ? (string) ($alert->getType() ?? 'Outro') : 'Alerta';
            $grouped[$type] = ($grouped[$type] ?? 0) + 1;
        }
        return $grouped;
    }

    private function nullableNumber(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
