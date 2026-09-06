<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WazeTrafficJam;
use App\Entity\WazeAlert;
use App\Entity\WazeIrregularity;
use App\Entity\WazeRoute;
use App\Entity\CemadenHydroData;
use App\Entity\CemadenData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/dashboard')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'app_dashboard')]
    #[Route('', name: 'dashboard_index')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        
        // Partner-scoped queries
        $partner = $user->getPartner();
        $partnerLabel = $partner ? $partner->getName() : 'Sem parceiro';
        
        // Periods configuration
        $periodKey = 'today';
        $periods = [
            'today' => ['label' => 'Úºltimas 24 horas', 'hours' => 24],
            'week' => ['label' => 'Úºltimos 7 dias', 'hours' => 168],
            'month' => ['label' => 'Úºltimos 30 dias', 'hours' => 720],
        ];
        
        // Waze metrics (last 24 hours)
        $trafficJamCount = $entityManager->getRepository(WazeTrafficJam::class)
            ->createQueryBuilder('tj')
            ->select('COUNT(tj.id)')
            ->where('tj.createdAt >= :yesterday')
            ->setParameter('yesterday', new \DateTimeImmutable('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();
        
        $alertCount = $entityManager->getRepository(WazeAlert::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt >= :yesterday')
            ->setParameter('yesterday', new \DateTimeImmutable('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();
        
        $irregularityCount = $entityManager->getRepository(WazeIrregularity::class)
            ->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
        
        $routeCount = $entityManager->getRepository(WazeRoute::class)
            ->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
        
        // Cemaden hydro metrics
        $hydroDataCount = $entityManager->getRepository(CemadenHydroData::class)
            ->createQueryBuilder('hd')
            ->select('COUNT(hd.id)')
            ->where('hd.createdAt >= :yesterday')
            ->setParameter('yesterday', new \DateTimeImmutable('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();
        
        // Recent traffic jams
        $recentTrafficJams = $entityManager->getRepository(WazeTrafficJam::class)
            ->findBy([], ['createdAt' => 'DESC'], 5);
        
        // Recent alerts
        $recentAlerts = $entityManager->getRepository(WazeAlert::class)
            ->findBy([], ['createdAt' => 'DESC'], 5);
        
        // Partner stats
        $partnerStats = [
            'jams' => (int) $trafficJamCount,
            'alerts' => (int) $alertCount,
            'irregularities' => (int) $irregularityCount,
            'routes' => (int) $routeCount,
            'hydroData' => (int) $hydroDataCount,
            'monitoredLinks' => 0,
            'cifsEvents' => 0,
            'executions' => 0,
        ];
        
        // Hero configuration
        $hero = [
            'title' => 'Dashboard',
            'subtitle' => 'VisÃ£o geral da plataforma',
            'jamsTotal' => (int) $trafficJamCount,
            'alertsTotal' => (int) $alertCount,
            'irregularitiesTotal' => (int) $irregularityCount,
            'routesTotal' => (int) $routeCount,
            'hydroDataTotal' => (int) $hydroDataCount,
            'jamsLast24h' => (int) $trafficJamCount,
            'alertsLast24h' => (int) $alertCount,
            'irregularitiesLast24h' => (int) $irregularityCount,
            'routesLast24h' => (int) $routeCount,
            'hydroDataLast24h' => (int) $hydroDataCount,
            'jamsLiveTotal' => 0,
            'jamsLiveMaxLevel' => 0,
            'jamsLiveMaxLevelLabel' => 'Sem jams ativos',
            'routesMonitored' => (int) $routeCount,
            'monitoredLinks' => 0,
            'monitoredCities' => 0,
            'cemadenReadings' => (int) $hydroDataCount,
            'cemadenCities' => 0,
            'tvtExecutions' => 0,
        ];
        
        // Chart data
        $alertsBySubtype = [];
        $jamsByLevel = [];
        $totalAlertsInPeriod = (int) $alertCount;
        
        // Top streets and map data
        $topStreets = [];
        $mapJams = $recentTrafficJams;
        $mapAlerts = $recentAlerts;
        $mapJamsTruncated = false;
        $mapAlertsTruncated = false;
        
        return $this->render('dashboard/index.html.twig', [
            'trafficJamCount' => (int) $trafficJamCount,
            'alertCount' => (int) $alertCount,
            'irregularityCount' => (int) $irregularityCount,
            'routeCount' => (int) $routeCount,
            'hydroDataCount' => (int) $hydroDataCount,
            'recentTrafficJams' => $recentTrafficJams,
            'recentAlerts' => $recentAlerts,
            'partner' => $partner,
            'partnerLabel' => $partnerLabel,
            'periods' => $periods,
            'periodKey' => $periodKey,
            'partnerStats' => $partnerStats,
            'hero' => $hero,
            'alertsBySubtype' => $alertsBySubtype,
            'jamsByLevel' => $jamsByLevel,
            'totalAlertsInPeriod' => $totalAlertsInPeriod,
            'topStreets' => $topStreets,
            'mapJams' => $mapJams,
            'mapAlerts' => $mapAlerts,
            'mapJamsTruncated' => $mapJamsTruncated,
            'mapAlertsTruncated' => $mapAlertsTruncated,
        ]);
    }
}
