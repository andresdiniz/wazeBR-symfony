<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenStationRepository;
use App\Repository\CemadenDataRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CemadenController extends AbstractController
{
    public function __construct(
        private CemadenStationRepository $stationRepository,
        private CemadenDataRepository $dataRepository
    ) {}

    #[Route('/cemaden', name: 'app_cemaden_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('cemaden/index.html.twig', [
            'controller_name' => 'CemadenController',
        ]);
    }

    /**
     * Página de quantidade de chuva - mostra pluviô££´metros cadastrados
     */
    #[Route('/cemaden/rainfall', name: 'app_cemaden_rainfall', methods: ['GET'])]
    public function rainfall(): Response
    {
        $stations = $this->stationRepository->findAll();
        
        foreach ($stations as $station) {
            $lastData = $this->dataRepository->findOneBy(
                ['station' => $station],
                ['createdAt' => 'DESC']
            );
            
            if ($lastData) {
                $station->lastRainfall = $lastData->getRainfall();
                $station->lastRainfallAt = $lastData->getCreatedAt();
            } else {
                $station->lastRainfall = null;
                $station->lastRainfallAt = null;
            }
        }
        
        return $this->render('cemaden/rainfall.html.twig', [
            'stations' => $stations,
        ]);
    }

    #[Route('/cemaden/station/{id}', name: 'app_cemaden_station_show', methods: ['GET'])]
    public function stationShow(int $id): Response
    {
        $station = $this->stationRepository->find($id);
        
        if (!$station) {
            throw $this->createNotFoundException('Estaçª£o nã££o encontrada');
        }
        
        $recentData = $this->dataRepository->findBy(
            ['station' => $station],
            ['createdAt' => 'DESC'],
            10
        );
        
        return $this->render('cemaden/station_show.html.twig', [
            'station' => $station,
            'recentData' => $recentData,
        ]);
    }
}
