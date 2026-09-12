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
    public function index(DashboardRepository $dashboardRepo): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $stats = $dashboardRepo->getDashboardStats();

        return $this->render('dashboard/index.html.twig', [
            'stats' => $stats,
        ]);
    }
}
