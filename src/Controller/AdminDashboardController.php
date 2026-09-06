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
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
class AdminDashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'admin_dashboard')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        // Global metrics (last 24 hours)
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

        // Recent items
        $recentTrafficJams = $entityManager->getRepository(WazeTrafficJam::class)
            ->findBy([], ['createdAt' => 'DESC'], 5);

        $recentAlerts = $entityManager->getRepository(WazeAlert::class)
            ->findBy([], ['createdAt' => 'DESC'], 5);

        return $this->render('admin/dashboard.html.twig', [
            'trafficJamCount' => $trafficJamCount,
            'alertCount' => $alertCount,
            'irregularityCount' => $irregularityCount,
            'routeCount' => $routeCount,
            'hydroDataCount' => $hydroDataCount,
            'recentTrafficJams' => $recentTrafficJams,
            'recentAlerts' => $recentAlerts,
        ]);
    }
}