<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AlertController extends AbstractController
{
    #[Route('/alerts', name: 'alert_index', methods: ['GET'])]
    public function index(WazeAlertRepository $alertRepository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $alerts = $alertRepository->findAllLatest(100);

        return $this->render('alert/index.html.twig', [
            'alerts' => $alerts,
        ]);
    }
}
