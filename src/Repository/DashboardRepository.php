<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Entity\WazeJam;
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

        $recentAlerts = $alertRepository->findBy([], ['id' => 'DESC'], 8);
        $recentJams = $jamRepository->findBy([], ['id' => 'DESC'], 8);
        $recentWeather = $weatherRepository->findBy([], ['id' => 'DESC'], 5);

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
            'alerts_by_type' => $alertsByType,
            'updated_at' => new \DateTimeImmutable(),
        ];
    }
}
