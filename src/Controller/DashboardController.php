<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DashboardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(DashboardRepository $dashboardRepository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $stats = $dashboardRepository->getDashboardStats();
        $stats['dashboard_data_source'] = 'Doctrine + SQL waze_tvt_route/waze_tvt_route_snapshot';

        return $this->render('dashboard/index.html.twig', [
            'stats' => $stats,
        ]);
    }
}
