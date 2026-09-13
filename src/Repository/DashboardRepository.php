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
            $routeId = $this->value($snapshot, 'getRouteId');
            if ($routeId !== null && $routeId !== '' && !isset($latestSnapshots[(string) $routeId])) {
                $latestSnapshots[(string) $routeId] = $snapshot;
            }
        }

        $recentRoutes = [];
        foreach ($routes as $route) {
            $routeId = $this->value($route, 'getId');
            $snapshot = $routeId !== null ? ($latestSnapshots[(string) $routeId] ?? null) : null;
            if ($snapshot === null) {
                continue;
            }

            $time = $this->number($snapshot, 'getTime');
            $historicTime = $this->number($snapshot, 'getHistoricTime');
            $delaySeconds = $time !== null && $historicTime !== null ? max(0, $historicTime - $time) : null;

            $recentRoutes[] = [
                'id' => $routeId,
                'route_id' => $routeId,
                'waze_route_id' => $this->value($route, 'getRouteId'),
                'name' => $this->value($snapshot, 'getName') ?? $this->value($route, 'getName') ?? 'Rota monitorada',
                'city' => $this->value($snapshot, 'getCity') ?? 'Local não informado',
                'state' => $this->value($snapshot, 'getState'),
                'status' => $delaySeconds !== null && $delaySeconds > 0 ? 'Atrasada' : 'Normal',
                'time' => $time,
                'historic_time' => $historicTime,
                'delay_seconds' => $delaySeconds,
                'delay_minutes' => $delaySeconds !== null ? round($delaySeconds / 60, 1) : null,
                'jam_level' => $this->value($snapshot, 'getJamLevel'),
                'recorded_at' => $this->value($snapshot, 'getRecordedAt'),
            ];
        }

        usort($recentRoutes, static fn (array $a, array $b): int => ($b['delay_seconds'] ?? -1) <=> ($a['delay_seconds'] ?? -1));

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

    private function value(object $entity, string $method): mixed
    {
        if (!method_exists($entity, $method)) {
            return null;
        }

        $value = $entity->{$method}();
        return is_scalar($value) || $value instanceof \DateTimeInterface || $value === null ? $value : null;
    }

    private function number(object $entity, string $method): ?float
    {
        $value = $this->value($entity, $method);
        return is_numeric($value) ? (float) $value : null;
    }
}
