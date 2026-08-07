<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\WazeTvtRouteRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\WazeIrregularityRepository;
use App\Repository\CifsEventRepository;
use App\Repository\WazeAlertTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private PartnerRepository $partnerRepo,
        private WazeAlertRepository $alertRepo,
        private WazeTrafficJamRepository $jamRepo,
        private WazeTvtRouteRepository $tvtRouteRepo,
        private MonitoredLinkRepository $linkRepo,
        private WazeIrregularityRepository $irregRepo,
        private CifsEventRepository $cifsRepo,
        private WazeAlertTypeRepository $alertTypeRepo,
    ) {}

    #[Route('/', name: 'dashboard_index')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $partner = $user->getPartner();

        if (!$partner) {
            return new Response(
                '<div style="font-family: sans-serif; padding: 40px; text-align: center;">'.
                '<h1>Usu\u00e1rio sem parceiro</h1>'.
                '<p>Seu usu\u00e1rio n\u00e3o est\u00e1 vinculado a nenhum parceiro.</p>'.
                '</div>',
                200
            );
        }

        $partnerLabel = $partner->getName() ?? $partner->getSlug() ?? 'Parceiro #' . $partner->getId();

        $alerts = $this->alertRepo->findAll();
        $jams = $this->jamRepo->findAll();
        $tvtRoutes = $this->tvtRouteRepo->findAll();
        $links = $this->linkRepo->findAll();
        $irregs = $this->irregRepo->findAll();
        $cifs = $this->cifsRepo->findAll();
        $alertTypes = $this->alertTypeRepo->findAll();

        // KPIs básicos
        $kpis = [
            'alerts' => count($alerts),
            'jams' => count($jams),
            'cemaden' => 0,
            'cities' => 0,
            'links' => count($links),
            'routes' => count($tvtRoutes),
            'irregularities' => count($irregs),
            'alerts1h' => 0,
            'alerts24h' => 0,
            'alerts7d' => 0,
            'jams24h' => 0,
            'jams7d' => 0,
            'avgSpeed' => 0.0,
            'avgDelay' => 0.0,
            'totalLength' => 0.0,
            'liveJams' => 0,
            'maxJamLevel' => 0,
            'liveAvgSpeed' => 0.0,
            'liveAvgDelay' => 0.0,
            'liveTotalLen' => 0.0,
            'worstJam' => null,
            'rainLastHour' => null,
            'hydro' => [
                'total' => 0,
                'normal' => 0,
                'attention' => 0,
                'alert' => 0,
                'flood' => 0,
                'overflow' => 0,
                'critical' => 0,
                'stations' => [],
            ],
            'tvtAvgSpeed' => 0.0,
            'tvtAvgTravelTime' => 0.0,
            'wazeCount' => null,
            'wazeCountLastWeek' => null,
            'wazeCountPeak' => [
                'max_level0' => null,
                'max_level1' => null,
                'max_level2' => null,
                'max_level3' => null,
                'max_level4' => null,
                'max_total' => null,
            ],
            'alertLinkedToJamPct' => 0.0,
            'alertOnHighwayPct' => 0.0,
            'cifsActiveCount' => 0,
            'anomalyDetected' => false,
            'anomalyRatio' => 1.0,
            'avg6hPerHour' => 0.0,
        ];

        // Alerts por tipo
        $alertsByType = [];
        foreach ($alertTypes as $type) {
            $alertsByType[] = [
                'type' => $type->getType(),
                'total' => 0,
            ];
        }

        // Jams por nível
        $jamsByLevel = [];
        for ($level = 0; $level <= 5; $level++) {
            $jamsByLevel[] = [
                'level' => $level,
                'total' => 0,
            ];
        }

        // Maps de label
        $typesMap = [
            'ACCIDENT' => 'Acidente',
            'JAM' => 'Congestionamento',
            'MISC' => 'Diversos',
            'CONSTRUCTION' => 'Obra',
            'ROAD_CLOSED' => 'Via Interditada',
            'HAZARD' => 'Perigo',
            'WEATHERHAZARD' => 'Perigo climático',
        ];

        // Subtypes map (simplificado)
        $subtypesMap = [
            'ACCIDENT|ACCIDENT_MINOR' => 'Acidente leve',
            'ACCIDENT|ACCIDENT_MAJOR' => 'Acidente grave',
            'ACCIDENT|NO_SUBTYPE' => 'Sem subtipo específico',
            'JAM|JAM_LIGHT_TRAFFIC' => 'Trâ©©nsito leve',
            'JAM|JAM_MODERATE_TRAFFIC' => 'Trâ©©nsito moderado',
            'JAM|JAM_HEAVY_TRAFFIC' => 'Trâ©©nsito intenso',
            'JAM|JAM_STAND_STILL_TRAFFIC' => 'Trâ©©nsito parado',
            'JAM|NO_SUBTYPE' => 'Sem subtipo específico',
            'MISC|NO_SUBTYPE' => 'Sem subtipo específico',
            'CONSTRUCTION|NO_SUBTYPE' => 'Sem subtipo específico',
            'ROAD_CLOSED|ROAD_CLOSED_HAZARD' => 'Interditada por perigo',
            'ROAD_CLOSED|ROAD_CLOSED_CONSTRUCTION' => 'Interditada por obra',
            'ROAD_CLOSED|ROAD_CLOSED_EVENT' => 'Interditada por evento',
            'ROAD_CLOSED|NO_SUBTYPE' => 'Sem subtipo específico',
            'HAZARD|HAZARD_ON_ROAD' => 'Perigo na via',
            'HAZARD|HAZARD_ON_SHOULDER' => 'Perigo no acostamento',
            'HAZARD|HAZARD_WEATHER' => 'Condiç©©o climatica',
            'HAZARD|NO_SUBTYPE' => 'Sem subtipo específico',
            'WEATHERHAZARD|HAZARD_WEATHER' => 'Condiç©©o climatica',
            'WEATHERHAZARD|NO_SUBTYPE' => 'Sem subtipo específico',
        ];

        return $this->render('dashboard/index.html.twig', [
            'partner' => $partner,
            'partnerLabel' => $partnerLabel,
            'kpis' => $kpis,
            'alertsByType' => $alertsByType,
            'jamsByLevel' => $jamsByLevel,
            'typesMap' => $typesMap,
            'subtypesMap' => $subtypesMap,
            'alertQualityByType' => [],
            'topEngagedAlerts' => [],
            'irregWorsening' => [],
            'irregSpeedLoss' => [],
            'irregDelayByStreet' => [],
            'irregSeverityCity' => [],
            'irregRecentList' => [],
            'cifsActive' => [],
            'cifsUpcoming' => [],
            'cifsActiveByType' => [],
            'cifsTopStreets' => [],
            'alertsPerHour' => [],
            'jamsPerHour' => [],
            'recentAlerts' => [],
            'recentJams' => [],
            'cemadenData' => [],
            'routes' => $tvtRoutes,
            'cities' => [],
            'links' => $links,
        ]);
    }
}
