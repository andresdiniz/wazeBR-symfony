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
    public function index(EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        
        // Partner-scoped queries
        $partner = $user->getPartner();
        
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
            ->where('r.createdAt >= :yesterday')
            ->setParameter('yesterday', new \DateTimeImmutable('-24 hours'))
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
        
        return $this->render('dashboard/index.html.twig', [
            'trafficJamCount' => (int) $trafficJamCount[0],
            'alertCount' => (int) $alertCount[0],
            'irregularityCount' => (int) $irregularityCount[0],
            'routeCount' => (int) $routeCount[0],
            'hydroDataCount' => (int) $hydroDataCount[0],
            'recentTrafficJams' => $recentTrafficJams,
            'recentAlerts' => $recentAlerts,
            'partner' => $partner,
        ]);
    }
}
