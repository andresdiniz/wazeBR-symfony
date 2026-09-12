<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeJamRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class JamController extends AbstractController
{
    #[Route('/jams', name: 'jam_index', methods: ['GET'])]
    public function index(WazeJamRepository $jamRepository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $jams = $jamRepository->findAllLatest(100);

        return $this->render('jam/index.html.twig', [
            'jams' => $jams,
        ]);
    }
}
