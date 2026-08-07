<?php

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\CemadenDataRepository;
use App\Repository\MonitoredCityRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\WazeTvtRouteRepository;
use App\Repository\WazeIrregularityRepository;
use App\Repository\CifsEventRepository;
use App\Repository\WazeCountRepository;
use App\Repository\CemadenHydroDataRepository;
use App\Repository\WazeAlertTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly WazeAlertRepository $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly CemadenDataRepository $cemadenRepo,
        private readonly MonitoredCityRepository $cityRepo,
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly WazeTvtRouteRepository $tvtRouteRepo,
        private readonly WazeIrregularityRepository $irregRepo,
        private readonly CifsEventRepository $cifsRepo,
        private readonly WazeCountRepository $wazeCountRepo,
        private readonly CemadenHydroDataRepository $hydroRepo,
        private readonly WazeAlertTypeRepository $alertTypeRepo,
    ) {}

    #[Route('/dashboard', name: 'dashboard_index')]
    public function index(): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_partner_index');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $partner = $user->getPartner();

        // ── Contagens base ────────────────────────────────────────────────────
        $alertCount = $this->alertRepo->countByPartner($partner);
        $jamCount = $this->jamRepo->countByPartner($partner);
        $cemadenCount = $this->cemadenRepo->countByPartner($partner);
        $cityCount = $this->cityRepo->countByPartner($partner);
        $linkCount = $this->linkRepo->countByPartner($partner);
        $routeCount = $this->tvtRouteRepo->countByPartner($partner);
        $irregCount = $this->irregRepo->countByPartner($partner);

        // ── KPIs temporais — alertas ──────────────────────────────────────────
        $alertsLast1h = $this->alertRepo->countLastHoursByPartner($partner, 1);
        $alertsLast24h = $this->alertRepo->countLastHoursByPartner($partner, 24);
        $alertsLast7d = $this->alertRepo->countLast7dByPartner($partner);

        // ── KPIs temporais — jams ─────────────────────────────────────────────
        $jamsLast24h = $this->jamRepo->countLast24hByPartner($partner);
        $jamsLast7d = $this->jamRepo->countLast7dByPartner($partner);
        $avgJamSpeed = $this->jamRepo->avgSpeedKmhByPartner($partner);
        $avgJamDelay = $this->jamRepo->avgDelaySecsByPartner($partner);
        $totalJamLength = $this->jamRepo->totalLengthMByPartner($partner);

        // ── KPIs das últimas 3h (jams ativos) ────────────────────────────────
        $liveStats = $this->jamRepo->avgStats($partner, 3);
        $liveJams = $this->jamRepo->findLiveByPartner($partner, 3);
        $maxJamLevel = count($liveJams) > 0
            ? max(array_map(fn($j) => $j->getLevel() ?? 0, $liveJams))
            : 0;
        $worstJam = $this->jamRepo->worstActiveJamByPartner($partner, 3);

        // ── KPI chuva acumulada — CEMADEN ─────────────────────────────────────
        $rainLastHour = $this->cemadenRepo->sumRainLastHourByPartner($partner);

        // ── KPIs hidroló·³·gicos — CemadenHydro ─────────────────────────────────
        $hydroKpis = $this->hydroRepo->kpiSummaryByPartner($partner);

        // ── KPIs TVT ──────────────────────────────────────────────────────────
        $tvtAvgSpeed = $this->tvtRouteRepo->avgSpeedByPartner($partner);
        $tvtAvgTravelTime = $this->tvtRouteRepo->avgTravelTimeByPartner($partner);
        $tvtJamLevelDist = $this->tvtRouteRepo->countGroupByJamLevel($partner);

        // ── WazeCount — usuá·½ios em congestionamentos ─────────────────────────
        $wazeCount = $this->wazeCountRepo->findLatest($partner);
        $wazeCountLastWeek = $this->wazeCountRepo->findSameTimeLastWeek($partner);
        $wazeCountPeak = $this->wazeCountRepo->peakOfDay($partner);

        // ── Qualidade de alertas (KPIs subaproveitados) ───────────────────────
        $alertQualityByType = $this->alertRepo->avgQualityByType($partner);
        $alertLinkedToJamPct = $this->alertRepo->percentLinkedToJam($partner);
        $alertOnHighwayPct = $this->alertRepo->percentOnHighways($partner);
        $topEngagedAlerts = $this->alertRepo->topEngagedAlerts($partner, 7, 5);

        // ── Irregularidades ───────────────────────────────────────────────────
        $irregWorsening = $this->irregRepo->findWorseningByPartner($partner, 10);
        $irregSpeedLoss = $this->irregRepo->speedLossRankingByStreet($partner, 24, 10);
        $irregDelayByStreet = $this->irregRepo->accumulatedDelayByStreet($partner, 24, 10);
        $irregSeverityCity = $this->irregRepo->avgSeverityByCity($partner);
        $irregRecentList = $this->irregRepo->findRecentByPartner($partner, 10);

        // ── CIFS — eventos de via ─────────────────────────────────────────────
        $cifsActive = $this->cifsRepo->findActiveByPartner($partner);
        $cifsActiveCount = $this->cifsRepo->countActive($partner);
        $cifsUpcoming = $this->cifsRepo->findUpcomingByPartner($partner, 7);
        $cifsActiveByType = $this->cifsRepo->countActiveByType($partner);
        $cifsTopStreets = $this->cifsRepo->topStreetsByActiveEvents($partner, 5);

        // ── Distribuiçı¥es para grá·³icos ───────────────────────────────────────
        $alertsByType = $this->alertRepo->countGroupByType($partner);
        $alertsBySubtype = $this->alertRepo->countGroupBySubtype($partner, 8);
        $alertsByCity = $this->alertRepo->countGroupByCity($partner, 10);
        $alertsByConf = $this->alertRepo->countByConfidence($partner);
        $topStreets = $this->alertRepo->topStreetsByAlert($partner, 10);
        $jamsByLevel = $this->jamRepo->countGroupByLevel($partner);
        $jamsByCity = $this->jamRepo->countGroupByCity($partner, 10);
        $jamLevelBreakdown = $this->jamRepo->levelBreakdownByPartner($partner);
        $alertsPerHour = $this->alertRepo->countPerHourLast24h($partner);
        $jamsPerHour = $this->jamRepo->countPerHourLast24h($partner);

        // ── Detector de anomalia: spike de alertas na ú•tima hora ─────────────
        $alertsLast6h = $this->alertRepo->countLastHoursByPartner($partner, 6);
        $avg6hPerHour = $alertsLast6h > 0 ? round($alertsLast6h / 6, 1) : 0;
        $anomalyDetected = $avg6hPerHour > 0 && $alertsLast1h > ($avg6hPerHour * 2);
        $anomalyRatio = $avg6hPerHour > 0 ? round($alertsLast1h / $avg6hPerHour, 1) : 0;

        $kpis = [
            'alerts' => $alertCount,
            'jams' => $jamCount,
            'cemaden' => $cemadenCount,
            'cities' => $cityCount,
            'links' => $linkCount,
            'routes' => $routeCount,
            'irregularities' => $irregCount,
            'alerts1h' => $alertsLast1h,
            'alerts24h' => $alertsLast24h,
            'alerts7d' => $alertsLast7d,
            'jams24h' => $jamsLast24h,
            'jams7d' => $jamsLast7d,
            'avgSpeed' => $avgJamSpeed,
            'avgDelay' => $avgJamDelay,
            'totalLength' => $totalJamLength,
            'liveJams' => count($liveJams),
            'maxJamLevel' => $maxJamLevel,
            'liveAvgSpeed' => $liveStats['avgSpeed'],
            'liveAvgDelay' => $liveStats['avgDelay'],
            'liveTotalLen' => $liveStats['totalLength'],
            'worstJam' => $worstJam,
            'rainLastHour' => $rainLastHour,
            'hydro' => $hydroKpis,
            'tvtAvgSpeed' => $tvtAvgSpeed,
            'tvtAvgTravelTime' => $tvtAvgTravelTime,
            'wazeCount' => $wazeCount,
            'wazeCountLastWeek' => $wazeCountLastWeek,
            'wazeCountPeak' => $wazeCountPeak,
            'alertLinkedToJamPct' => $alertLinkedToJamPct,
            'alertOnHighwayPct' => $alertOnHighwayPct,
            'cifsActiveCount' => $cifsActiveCount,
            'anomalyDetected' => $anomalyDetected,
            'anomalyRatio' => $anomalyRatio,
            'avg6hPerHour' => $avg6hPerHour,
        ];

        dump([
            'partner' => $partner,
            'kpis' => $kpis,
            'alertsByType' => $alertsByType,
            'alertsByCity' => $alertsByCity,
            'jamsByLevel' => $jamsByLevel,
            'cifsActiveCount' => $cifsActiveCount,
            'irregSpeedLoss' => $irregSpeedLoss,
            'irregDelayByStreet' => $irregDelayByStreet,
        ]);

        return $this->render('dashboard/index.html.twig', [
            'partner' => $partner,
            'typesMap' => $this->alertTypeRepo->getTypesMap('pt'),
            'subtypesMap' => $this->alertTypeRepo->getSubtypesMap('pt'),
            'kpis' => $kpis,
            'alertQualityByType' => $alertQualityByType,
            'topEngagedAlerts' => $topEngagedAlerts,
            'irregWorsening' => $irregWorsening,
            'irregSpeedLoss' => $irregSpeedLoss,
            'irregDelayByStreet' => $irregDelayByStreet,
            'irregSeverityCity' => $irregSeverityCity,
            'irregRecentList' => $irregRecentList,
            'cifsActive' => $cifsActive,
            'cifsUpcoming' => $cifsUpcoming,
            'cifsActiveByType' => $cifsActiveByType,
            'cifsTopStreets' => $cifsTopStreets,
            'alertsByType' => $alertsByType,
            'alertsBySubtype' => $alertsBySubtype,
            'alertsByCity' => $alertsByCity,
            'alertsByConf' => $alertsByConf,
            'topStreets' => $topStreets,
            'jamsByLevel' => $jamsByLevel,
            'jamsByCity' => $jamsByCity,
            'jamLevelBreakdown' => $jamLevelBreakdown,
            'tvtJamLevelDist' => $tvtJamLevelDist,
            'alertsPerHour' => $alertsPerHour,
            'jamsPerHour' => $jamsPerHour,
            'recentAlerts' => $this->alertRepo->findRecentByPartner($partner, 10),
            'recentJams' => $this->jamRepo->findRecentByPartner($partner, 5),
            'cemadenData' => $this->cemadenRepo->findByPartner($partner),
            'routes' => $this->tvtRouteRepo->findRecentByPartner($partner, 20),
            'cities' => $this->cityRepo->findByPartner($partner),
            'links' => $this->linkRepo->findByPartner($partner),
        ]);
    }

    #[Route('/api/live', name: 'api_live', methods: ['GET'])]
    public function apiLive(): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $partner = $user->getPartner();

        $alertsLast1h = $this->alertRepo->countLastHoursByPartner($partner, 1);
        $alertsLast6h = $this->alertRepo->countLastHoursByPartner($partner, 6);
        $avg6hPerHour = $alertsLast6h > 0 ? round($alertsLast6h / 6, 1) : 0;
        $liveJams = $this->jamRepo->findLiveByPartner($partner, 3);
        $liveStats = $this->jamRepo->avgStats($partner, 3);
        $rainLastHour = $this->cemadenRepo->sumRainLastHourByPartner($partner);
        $wazeCount = $this->wazeCountRepo->findLatest($partner);
        $cifsActive = $this->cifsRepo->countActive($partner);

        return $this->json([
            'alerts1h' => $alertsLast1h,
            'anomaly' => [
                'detected' => $avg6hPerHour > 0 && $alertsLast1h > ($avg6hPerHour * 2),
                'ratio' => $avg6hPerHour > 0 ? round($alertsLast1h / $avg6hPerHour, 1) : 0,
                'avg6h' => $avg6hPerHour,
            ],
            'liveJams' => count($liveJams),
            'liveAvgSpeed' => $liveStats['avgSpeed'],
            'liveAvgDelay' => $liveStats['avgDelay'],
            'rainLastHour' => $rainLastHour,
            'wazeJams' => $wazeCount?->getTotalJams(),
            'wazeAlerts' => $wazeCount?->getTotalAlerts(),
            'cifsActive' => $cifsActive,
            'collectedAt' => (new \DateTimeImmutable())->format('H:i:s'),
        ]);
    }

    #[Route('/mapa', name: 'map')]
    public function map(): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_partner_index');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $partner = $user->getPartner();

        return $this->render('dashboard/map.html.twig', [
            'partner' => $partner,
            'alerts' => $this->alertRepo->findActiveByPartner($partner),
            'jams' => $this->jamRepo->findActiveByPartner($partner),
            'cemaden' => $this->cemadenRepo->findByPartner($partner),
            'routes' => $this->tvtRouteRepo->findRecentByPartner($partner, 50),
        ]);
    }
}
