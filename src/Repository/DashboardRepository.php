<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for dashboard statistics
 */
class DashboardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, \App\Entity\WazeAlert::class);
    }

    public function getDashboardStats(): array
    {
        $em = $this->getEntityManager();
        
        // Waze Alerts
        $totalAlerts = $em->getRepository(\App\Entity\WazeAlert::class)->count([]);
        $recentAlerts = $em->getRepository(\App\Entity\WazeAlert::class)->findBy([], ['id' => 'DESC'], 5);
        
        // Waze Jams
        $totalJams = $em->getRepository(\App\Entity\WazeJam::class)->count([]);
        $recentJams = $em->getRepository(\App\Entity\WazeJam::class)->findBy([], ['id' => 'DESC'], 5);
        
        // Weather
        $totalWeather = $em->getRepository(\App\Entity\WeatherObservation::class)->count([]);
        $recentWeather = $em->getRepository(\App\Entity\WeatherObservation::class)->findBy([], ['id' => 'DESC'], 3);
        
        // Partners
        $totalPartners = $em->getRepository(\App\Entity\Partner::class)->count([]);
        
        // Users
        $totalUsers = $em->getRepository(\App\Entity\User::class)->count([]);
        
        return [
            'total_alerts' => $totalAlerts,
            'total_jams' => $totalJams,
            'total_weather' => $totalWeather,
            'total_partners' => $totalPartners,
            'total_users' => $totalUsers,
            'recent_alerts' => $recentAlerts,
            'recent_jams' => $recentJams,
            'recent_weather' => $recentWeather,
        ];
    }
}
