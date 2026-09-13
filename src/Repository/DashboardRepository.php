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
        $routes = $routeRepository->findBy([], ['id' => 'DESC'], 20);
        $snapshots = $snapshotRepository->findBy([], ['id' => 'DESC'], 100);

        $latestSnapshots = [];
        foreach ($snapshots as $snapshot) {
            $routeKey = $this->routeKey($snapshot);
            if ($routeKey !== null && !isset($latestSnapshots[$routeKey])) {
                $latestSnapshots[$routeKey] = $snapshot;
            }
        }

        $recentRoutes = [];
        foreach ($routes as $route) {
            $routeKey = $this->routeKey($route);
            $snapshot = $routeKey !== null ? ($latestSnapshots[$routeKey] ?? null) : null;
            $recentRoutes[] = [
                'id' => $this->value($route, ['getId']),
                'name' => $this->firstValue($route, ['getName', 'getRouteName', 'getSlug', 'getCode']) ?? 'Rota monitorada',
                'city' => $this->firstValue($route, ['getCity', 'getMunicipality', 'getRegion']),
                'origin' => $this->firstValue($route, ['getOrigin', 'getStartAddress', 'getStart']),
                'destination' => $this->firstValue($route, ['getDestination', 'getEndAddress', 'getEnd']),
                'status' => $snapshot !== null ? 'Atualizada' : 'Sem snapshot',
                'snapshot_at' => $snapshot !== null ? $this->firstValue($snapshot, ['getCreatedAt', 'getCapturedAt', 'getObservedAt', 'getDate']) : null,
                'duration' => $snapshot !== null ? $this->firstValue($snapshot, ['getDurationSeconds', 'getDuration', 'getTravelTimeSeconds', 'getTravelTime']) : null,
                'delay' => $snapshot !== null ? $this->firstValue($snapshot, ['getDelaySeconds', 'getDelay', 'getDelayMinutes']) : null,
                'distance' => $snapshot !== null ? $this->firstValue($snapshot, ['getDistanceMeters', 'getDistanceKm', 'getDistance']) : null,
            ];
        }

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

    private function routeKey(object $entity): ?string
    {
        foreach (['getRouteId', 'getWazeTvtRoute', 'getRoute', 'getRouteKey', 'getId'] as $method) {
            if (!method_exists($entity, $method)) {
                continue;
            }
            $value = $entity->{$method}();
            if (is_object($value) && method_exists($value, 'getId')) {
                $value = $value->getId();
            }
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function firstValue(object $entity, array $methods): mixed
    {
        foreach ($methods as $method) {
            $value = $this->value($entity, [$method]);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function value(object $entity, array $methods): mixed
    {
        foreach ($methods as $method) {
            if (method_exists($entity, $method)) {
                $value = $entity->{$method}();
                if (is_scalar($value) || $value instanceof \DateTimeInterface || $value === null) {
                    return $value;
                }
            }
        }

        return null;
    }
}
