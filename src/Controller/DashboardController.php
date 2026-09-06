<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WazeTrafficJam;
use App\Entity\WazeAlert;
use App\Entity\WazeIrregularity;
use App\Entity\WazeRoute;
use App\Entity\CemadenHydroData;
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
        
        $partner = $user->getPartner();
        $partnerLabel = $partner ? $partner->getName() : 'Sem parceiro';
        
        $periodKey = 'today';
        $periods = [
            'today' => ['label' => 'Úºltimas 24 horas', 'hours' => 24],
            'week' => ['label' => 'Úºltimos 7 dias', 'hours' => 168],
            'month' => ['label' => 'Úºltimos 30 dias', 'hours' => 720],
        ];
        
        $trafficJamCount = (int) $entityManager->getRepository(WazeTrafficJam::class)
            ->createQueryBuilder('tj')
            ->select('COUNT(tj.id)')
            ->where('tj.createdAt >= :yesterday')
            ->setParameter('yesterday', new \DateTimeImmutable('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();
        
        $alertCount = (int) $entityManager->getRepository(WazeAlert::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt >= :yesterday')
            ->setParameter('yesterday', new \DateTimeImmutable('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();
        
        $irregularityCount = (int) $entityManager->getRepository(WazeIrregularity::class)
            ->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
        
        $routeCount = (int) $entityManager->getRepository(WazeRoute::class)
            ->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
        
        $hydroDataCount = (int) $entityManager->getRepository(CemadenHydroData::class)
            ->createQueryBuilder('hd')
            ->select('COUNT(hd.id)')
            ->where('hd.createdAt >= :yesterday')
            ->setParameter('yesterday', new \DateTimeImmutable('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();
        
        $recentTrafficJams = $entityManager->getRepository(WazeTrafficJam::class)
            ->findBy([], ['createdAt' => 'DESC'], 5);
        
        $recentAlerts = $entityManager->getRepository(WazeAlert::class)
            ->findBy([], ['createdAt' => 'DESC'], 5);
        
        $hero = [
            'title' => 'Dashboard',
            'subtitle' => 'VisÃ£o geral da plataforma',
            'jamsTotal' => $trafficJamCount,
            'alertsTotal' => $alertCount,
            'irregularitiesTotal' => $irregularityCount,
            'routesTotal' => $routeCount,
            'hydroDataTotal' => $hydroDataCount,
            'jamsLast24h' => $trafficJamCount,
            'alertsLast24h' => $alertCount,
            'irregularitiesLast24h' => $irregularityCount,
            'routesLast24h' => $routeCount,
            'hydroDataLast24h' => $hydroDataCount,
            'jamsLiveTotal' => 0,
            'jamsLiveMaxLevel' => 0,
            'jamsLiveMaxLevelLabel' => 'Sem jams ativos',
            'routesMonitored' => $routeCount,
            'monitoredLinks' => 0,
            'monitoredCities' => 0,
            'cemadenReadings' => $hydroDataCount,
            'cemadenCities' => 0,
            'tvtExecutions' => 0,
        ];
        
        $alertsBySubtype = [];
        $jamsByLevel = [];
        $totalAlertsInPeriod = $alertCount;
        $topStreets = [];
        
        $mapJams = array_map(function($jam) {
            return [
                'lat' => $jam->getLat(),
                'lng' => $jam->getLng(),
                'street' => $jam->getStreetName(),
                'city' => $jam->getCity(),
                'level' => $jam->getLevel(),
                'createdAt' => $jam->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
        }, $recentTrafficJams);
        
        $mapAlerts = array_map(function($alert) {
            return [
                'lat' => $alert->getLat(),
                'lng' => $alert->getLng(),
                'type' => $alert->getSubtype(),
                'street' => $alert->getStreetName(),
                'reportedAt' => $alert->getReportedAt()?->format('Y-m-d H:i:s'),
            ];
        }, $recentAlerts);
        
        $mapJamsTruncated = false;
        $mapAlertsTruncated = false;
        
        $isSuperAdmin = true;
        $isAdmin = true;
        $showPartnerDashboard = ($partner !== null);
        
        return $this->render('dashboard/index.html.twig', [
            'trafficJamCount' => $trafficJamCount,
            'alertCount' => $alertCount,
            'recentTrafficJams' => $recentTrafficJams,
            'recentJams' => $recentTrafficJams,
            'recentAlerts' => $recentAlerts,
            'partner' => $partner,
            'partnerLabel' => $partnerLabel,
            'periods' => $periods,
            'periodKey' => $periodKey,
            'hero' => $hero,
            'alertsBySubtype' => $alertsBySubtype,
            'jamsByLevel' => $jamsByLevel,
            'totalAlertsInPeriod' => $totalAlertsInPeriod,
            'topStreets' => $topStreets,
            'mapJams' => $mapJams,
            'mapAlerts' => $mapAlerts,
            'mapJamsTruncated' => $mapJamsTruncated,
            'mapAlertsTruncated' => $mapAlertsTruncated,
            'isSuperAdmin' => $isSuperAdmin,
            'isAdmin' => $isAdmin,
            'showPartnerDashboard' => $showPartnerDashboard,
        ]);
    }
}