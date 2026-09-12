<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeJamRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(
        WazeAlertRepository $alertRepository,
        WazeJamRepository $jamRepository
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $stats = [
            'total_alerts' => count($alertRepository->findAll()),
            'total_jams' => count($jamRepository->findAll()),
            'recent_alerts' => $alertRepository->findAllLatest(5),
            'recent_jams' => $jamRepository->findAllLatest(5),
        ];

        return $this->render('dashboard/index.html.twig', [
            'stats' => $stats,
        ]);
    }
}
