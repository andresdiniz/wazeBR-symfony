<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Entity\WazeJam;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteSnapshot;
use App\Entity\WeatherObservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class DashboardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    /** @return array<string, mixed> */
    public function getDashboardStats(): array
    {
        $em = $this->getEntityManager();
        $alertRepository = $em->getRepository(WazeAlert::class);
        $jamRepository = $em->getRepository(WazeJam::class);
        $weatherRepository = $em->getRepository(WeatherObservation::class);
        $routeRepository = $em->getRepository(WazeTvtRoute::class);
        $snapshotRepository = $em->getRepository(WazeTvtRouteSnapshot::class);

        $recentAlerts = $alertRepository->findBy([], ['id' => 'DESC'], 8);
        $recentJams = $jamRepository->findBy([], ['id' => 'DESC'], 8);
        $recentWeather = $weatherRepository->findBy([], ['id' => 'DESC'], 5);
        $routes = $routeRepository->findBy([], ['id' => 'DESC'], 100);
        $snapshots = $snapshotRepository->findBy([], ['recordedAt' => 'DESC', 'id' => 'DESC'], 500);

        $latestSnapshots = [];
        foreach ($snapshots as $snapshot) {
            $routeId = $this->scalarValue($snapshot, ['getRouteId', 'getWazeRouteId']);
            if ($routeId !== null && $routeId !== '' && !isset($latestSnapshots[(string) $routeId])) {
                $latestSnapshots[(string) $routeId] = $snapshot;
            }
        }

        $recentRoutes = [];
        foreach ($routes as $route) {
            $routeId = $this->scalarValue($route, ['getId']);
            $snapshot = $routeId !== null ? ($latestSnapshots[(string) $routeId] ?? null) : null;
            if ($snapshot === null) {
                continue;
            }

            $time = $this->numericValue($snapshot, ['getTime']);
            $historicTime = $this->numericValue($snapshot, ['getHistoricTime']);
            $delaySeconds = $time !== null && $historicTime !== null ? max(0, $historicTime - $time) : null;
            $jamLevel = $this->scalarValue($snapshot, ['getJamLevel']);
            $recordedAt = $this->firstValue($snapshot, ['getRecordedAt']);

            $recentRoutes[] = [
                'id' => $routeId,
                'name' => $this->firstValue($snapshot, ['getName']) ?? $this->firstValue($route, ['getName', 'getRouteName', 'getSlug', 'getCode']) ?? 'Rota monitorada',
                'city' => $this->firstValue($snapshot, ['getCity']) ?? $this->firstValue($route, ['getCity', 'getMunicipality', 'getRegion']),
                'status' => $delaySeconds !== null && $delaySeconds > 0 ? 'Atrasada' : 'Normal',
                'time' => $time,
                'historic_time' => $historicTime,
                'delay_seconds' => $delaySeconds,
                'delay_minutes' => $delaySeconds !== null ? round($delaySeconds / 60, 1) : null,
                'jam_level' => $jamLevel,
                'recorded_at' => $recordedAt,
            ];
        }

        usort($recentRoutes, static fn (array $left, array $right): int => ($right['delay_seconds'] ?? -1) <=> ($left['delay_seconds'] ?? -1));

        $alertsByType = [];
        foreach ($recentAlerts as $alert) {
            $type = method_exists($alert, 'getType') ? (string) ($alert->getType() ?? 'Outro') : 'Alerta';
            $alertsByType[$type] = ($alertsByType[$type] ?? 0) + 1;
        }

        return [
            'total_alerts' => $alertRepository->count([]),
            'total_jams' => $jamRepository->count([]),
            'total_weather' => $weatherRepository->count([]),
            'total_partners' => $em->getRepository(Partner::class)->count([]),
            'total_users' => $em->getRepository(User::class)->count([]),
            'recent_alerts' => $recentAlerts,
            'recent_jams' => $recentJams,
            'recent_weather' => $recentWeather,
            'recent_routes' => $recentRoutes,
            'alerts_by_type' => $alertsByType,
            'updated_at' => new \DateTimeImmutable(),
        ];
    }

    private function scalarValue(object $entity, array $methods): mixed
    {
        foreach ($methods as $method) {
            if (!method_exists($entity, $method)) {
                continue;
            }
            $value = $entity->{$method}();
            if (is_scalar($value) || $value === null) {
                return $value;
            }
        }

        return null;
    }

    private function numericValue(object $entity, array $methods): ?float
    {
        $value = $this->scalarValue($entity, $methods);
        return is_numeric($value) ? (float) $value : null;
    }

    private function firstValue(object $entity, array $methods): mixed
    {
        foreach ($methods as $method) {
            if (!method_exists($entity, $method)) {
                continue;
            }
            $value = $entity->{$method}();
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
