<?php

namespace App\Controller;

use App\Repository\WazeTrafficJamRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/traffic-jams')]
class WazeTrafficJamController extends AbstractController
{
    #[Route('/', name: 'traffic_jam_index', methods: ['GET'])]
    public function index(WazeTrafficJamRepository $repo): Response
    {
        return $this->render('waze_traffic_jam/index.html.twig', [
            'jams' => $repo->findBy([], ['id' => 'DESC'], 50),
        ]);
    }
}
