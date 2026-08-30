<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StationType;
use App\Repository\CemadenDataRepository;
use App\Repository\CemadenHydroDataRepository;
use App\Repository\CemadenStationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/cemaden')]
#[IsGranted('ROLE_USER')]
class CemadenController extends AbstractController
{
    public function __construct(
        private readonly CemadenStationRepository $stationRepository,
        private readonly CemadenDataRepository $dataRepository,
        private readonly CemadenHydroDataRepository $hydroDataRepository,
    ) {
    }

    #[Route(path: '', name: 'app_cemaden_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_cemaden_rainfall');
    }

    #[Route(path: '/rainfall', name: 'app_cemaden_rainfall', methods: ['GET'])]
    public function rainfall(): Response
    {
        $stations = $this->stationRepository->findBy(
            [
                'stationType' => StationType::PLUVIOMETRIC,
                'isActive' => true,
            ],
            [
                'nome' => 'ASC',
            ],
        );

        $stationCards = [];

        foreach ($stations as $station) {
            $lastData = $this->dataRepository->findOneBy(
                [
                    'stationCode' => $station->getCodEstacao(),
                ],
                [
                    'measuredAt' => 'DESC',
                ],
            );

            $stationCards[] = [
                'station' => $station,
                'lastRainfall' => $lastData?->getAccumulatedRain(),
                'lastRainfallAt' => $lastData?->getMeasuredAt(),
                'alertLevel' => $lastData?->getAlertLevel(),
            ];
        }

        return $this->render('cemaden/rainfall.html.twig', [
            'stationCards' => $stationCards,
        ]);
    }

    #[Route(path: '/hydro', name: 'app_cemaden_hydro', methods: ['GET'])]
    #[Route(path: '/hydro/live', name: 'hydro_live', methods: ['GET'])]
    public function hydro(): Response
    {
        $stations = $this->stationRepository->findBy(
            [
                'stationType' => StationType::HYDROLOGICAL,
                'isActive' => true,
            ],
            [
                'nome' => 'ASC',
            ],
        );

        $hydroCards = [];

        foreach ($stations as $station) {
            $lastHydro = $this->hydroDataRepository->findOneBy(
                [
                    'stationCode' => $station->getCodEstacao(),
                ],
                [
                    'measuredAt' => 'DESC',
                ],
            );

            $hydroCards[] = [
                'station' => $station,
                'waterLevel' => $lastHydro?->getWaterLevel(),
                'offsetValue' => $lastHydro?->getOffsetValue(),
                'cotaAtencao' => $lastHydro?->getCotaAtencao(),
                'cotaAlerta' => $lastHydro?->getCotaAlerta(),
                'cotaTransbordamento' => $lastHydro?->getCotaTransbordamento(),
                'alertLevel' => $lastHydro?->getAlertLevel(),
                'lastUpdate' => $lastHydro?->getMeasuredAt(),
            ];
        }

        return $this->render('cemaden/hydro.html.twig', [
            'hydroCards' => $hydroCards,
        ]);
    }

    #[Route(
        path: '/station/{id}',
        name: 'app_cemaden_station_show',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function stationShow(int $id): Response
    {
        $station = $this->stationRepository->find($id);

        if ($station === null) {
            throw $this->createNotFoundException('Estação não encontrada.');
        }

        if ($station->getStationType() === StationType::HYDROLOGICAL) {
            return $this->redirectToRoute('app_cemaden_hydro');
        }

        $recentRain = $this->dataRepository->findBy(
            [
                'stationCode' => $station->getCodEstacao(),
            ],
            [
                'measuredAt' => 'DESC',
            ],
            10,
        );

        return $this->render('cemaden/station_show.html.twig', [
            'station' => $station,
            'recentRain' => $recentRain,
        ]);
    }
}
