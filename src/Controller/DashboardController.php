<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\WazeRouteRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\MonitoredCityRepository;
use App\Repository\CifsEventRepository;
use App\Repository\WazeTvtRouteExecutionRepository;
use App\Repository\WazeTvtRouteRepository;
use App\Repository\UserRepository;
use App\Repository\PartnerRepository;
use App\Repository\CemadenDataRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WazeAlertRepository $alertRepository,
        private readonly WazeTrafficJamRepository $jamRepository,
        private readonly WazeRouteRepository $routeRepository,
        private readonly MonitoredLinkRepository $linkRepository,
        private readonly MonitoredCityRepository $cityRepository,
        private readonly CifsEventRepository $cifsRepository,
        private readonly WazeTvtRouteExecutionRepository $executionRepository,
        private readonly WazeTvtRouteRepository $tvtRouteRepository,
        private readonly UserRepository $userRepository,
        private readonly PartnerRepository $partnerRepository,
        private readonly CemadenDataRepository $cemadenRepository,
    ) {
    }

    #[Route('/dashboard', name: 'dashboard_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $partner = method_exists($user, 'getPartner')
            ? $user->getPartner()
            : null;

        $partnerId = $partner && method_exists($partner, 'getId')
            ? $partner->getId()
            : null;

        $partnerLabel = $partner && method_exists($partner, 'getName')
            ? ($partner->getName() ?: 'Parceiro')
            : 'Sem parceiro';

        $periods = [
            '2_week' => [
                'label' => 'Últimas 2 semanas',
                'interval' => '-14 days',
            ],
            '7_days' => [
                'label' => 'Últimos 7 dias',
                'interval' => '-7 days',
            ],
            '24_hours' => [
                'label' => 'Últimas 24 horas',
                'interval' => '-24 hours',
            ],
        ];

        $periodKey = (string) $request->query->get('period', '2_week');

        if (!isset($periods[$periodKey])) {
            $periodKey = '2_week';
        }

        $periodFrom = new \DateTimeImmutable($periods[$periodKey]['interval']);
        $periodTo = new \DateTimeImmutable();
        $last24Hours = new \DateTimeImmutable('-24 hours');
        $last3Hours = new \DateTimeImmutable('-3 hours');

        $isSuperAdmin = $this->isGranted('ROLE_SUPER_ADMIN');
        $hasPartnerContext = $partnerId !== null;

        // ============================================================
        // Inicializa variáveis com valores padrão
        // ============================================================
        $alertsTotal = 0;
        $alertsLast24h = 0;
        $jamsTotal = 0;
        $jamsLast24h = 0;
        $jamsLiveTotal = 0;
        $routesTotal = 0;
        $monitoredLinks = 0;
        $monitoredCities = 0;
        $cifsEvents = 0;
        $executions = 0;
        $tvtRoutes = 0;
        $recentAlerts = [];
        $recentJams = [];
        $jamsByLevel = [];
        $alertsBySubtype = [];
        $topStreets = [];
        $mapJams = [];
        $mapAlerts = [];
        $mapJamsTruncated = false;
        $mapAlertsTruncated = false;
        $jamsLiveMaxLevel = 0;

        // ============================================================
        // Se o usuário tem parceiro, busca dados operacionais
        // ============================================================
        if ($hasPartnerContext) {
            $alertsTotal = $this->alertRepository->countInPeriod($partner, $periodFrom, $periodTo);
            $alertsLast24h = $this->alertRepository->countInPeriod($partner, $last24Hours, $periodTo);

            $jamsTotal = $this->jamRepository->countInPeriod($partner, $periodFrom, $periodTo);
            $jamsLast24h = $this->jamRepository->countInPeriod($partner, $last24Hours, $periodTo);
            $jamsLiveTotal = $this->jamRepository->countInPeriod($partner, $last3Hours, $periodTo);

            $routesTotal = $this->routeRepository->countRoutesByPartner($partner);
            $monitoredLinks = $this->linkRepository->countByPartner($partner);
            $monitoredCities = $this->cityRepository->countByPartner($partner);
            $cifsEvents = $this->cifsRepository->countInPeriod($partner, $periodFrom, $periodTo);
            $executions = $this->executionRepository->countInPeriod($periodFrom, $periodTo);
            $tvtRoutes = $this->tvtRouteRepository->count([]);

            $recentAlerts = $this->alertRepository->findRecentByPartner($partner, 10);
            $recentJams = $this->jamRepository->findRecentByPartner($partner, 10);

            $jamsByLevel = $this->jamRepository->countByLevelInPeriod($partner, $periodFrom, $periodTo);
            $alertsBySubtype = $this->alertRepository->countBySubtypeInPeriod($partner, $periodFrom, $periodTo, 10);
            $topStreets = $this->jamRepository->topStreetsInPeriod($partner, $periodFrom, $periodTo, 10);

            $mapJams = $this->jamRepository->findForMapInPeriod($partner, $periodFrom, $periodTo, 100);
            $mapAlerts = $this->alertRepository->findForMapFiltered($partner, ['dateFrom' => $periodFrom->format('Y-m-d')], 100);

            $mapJamsTruncated = count($mapJams) >= 100;
            $mapAlertsTruncated = count($mapAlerts) >= 100;

            $jamsLiveMaxLevel = 0;
            $liveJams = $this->jamRepository->findLiveByPartner($partner, 3, 100);
            foreach ($liveJams as $jam) {
                if ($jam->getLevel() > $jamsLiveMaxLevel) {
                    $jamsLiveMaxLevel = $jam->getLevel();
                }
            }
        }

        $partnerStats = [
            'alerts' => $alertsTotal,
            'jams' => $jamsTotal,
            'routes' => $routesTotal,
            'tvtRoutes' => $tvtRoutes,
            'monitoredLinks' => $monitoredLinks,
            'irregularities' => 0,
            'cifsEvents' => $cifsEvents,
            'executions' => $executions,
        ];

        $hero = [
            'alertsLast24h' => $alertsLast24h,
            'alertsTotal' => $alertsTotal,
            'jamsLast24h' => $jamsLast24h,
            'jamsTotal' => $jamsTotal,
            'jamsLiveTotal' => $jamsLiveTotal,
            'jamsLiveMaxLevel' => $jamsLiveMaxLevel,
            'jamsLiveMaxLevelLabel' => $jamsLiveMaxLevel > 0 ? 'Nível ' . $jamsLiveMaxLevel : null,
            'routesMonitored' => $routesTotal,
            'monitoredLinks' => $monitoredLinks,
            'monitoredCities' => $monitoredCities,
            'cemadenReadings' => 0,
            'cemadenCities' => 0,
        ];

        $periodStats = [
            'alerts' => $alertsTotal,
            'jams' => $jamsTotal,
            'routes' => $routesTotal,
            'tvtRoutes' => $tvtRoutes,
            'monitoredLinks' => $monitoredLinks,
            'irregularities' => 0,
            'cifsEvents' => $cifsEvents,
            'executions' => $executions,
        ];

        // ============================================================
        // Dados para o Super Admin (estatísticas da plataforma)
        // ============================================================
        $superAdminData = null;
        if ($isSuperAdmin) {
            $partners = $this->partnerRepository->findAllActive();
            $partnerStatsCollection = [];

            foreach ($partners as $p) {
                $partnerStatsCollection[] = [
                    'partner' => $p,
                    'alerts' => $this->alertRepository->countByPartner($p),
                    'jams' => $this->jamRepository->countByPartner($p),
                    'users' => $this->userRepository->countByPartner($p),
                    'links' => $this->linkRepository->countByPartner($p),
                    'cemaden' => $this->cemadenRepository->countByPartner($p),
                    'routes' => $this->routeRepository->countRoutesByPartner($p),
                ];
            }

            $totalAlerts = array_sum(array_column($partnerStatsCollection, 'alerts'));
            $totalJams = array_sum(array_column($partnerStatsCollection, 'jams'));
            $totalUsers = array_sum(array_column($partnerStatsCollection, 'users'));
            $totalLinks = array_sum(array_column($partnerStatsCollection, 'links'));
            $totalCemaden = array_sum(array_column($partnerStatsCollection, 'cemaden'));

            $dbSize = $this->getDatabaseSize();

            $superAdminData = [
                'totalPartners' => count($partners),
                'totalUsers' => $totalUsers,
                'totalAlerts' => $totalAlerts,
                'totalJams' => $totalJams,
                'totalLinks' => $totalLinks,
                'totalCemaden' => $totalCemaden,
                'activeCrons' => 3,
                'totalCrons' => 5,
                'storageUsed' => round($dbSize / 1024, 1),
                'storageLimit' => 10,
                'lastActivity' => new \DateTimeImmutable('-2 hours'),
                'nextBilling' => new \DateTimeImmutable('+28 days'),
                'plan' => 'Enterprise',
                'partnerStats' => $partnerStatsCollection,
                'activeFeatures' => [
                    'alerts_monitoring' => true,
                    'jams_monitoring' => true,
                    'routes_tracking' => true,
                    'cifs_integration' => false,
                    'hydrological' => false,
                ],
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'count' => $executions,
            'partnerLabel' => $partnerLabel,
            'periods' => $periods,
            'periodKey' => $periodKey,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'partnerStats' => $partnerStats,
            'hero' => $hero,
            'periodStats' => $periodStats,
            'totalAlertsInPeriod' => $alertsTotal,
            'alertsBySubtype' => $alertsBySubtype,
            'jamsByLevel' => $jamsByLevel,
            'topStreets' => $topStreets,
            'mapJams' => $mapJams,
            'mapAlerts' => $mapAlerts,
            'mapJamsTruncated' => $mapJamsTruncated,
            'mapAlertsTruncated' => $mapAlertsTruncated,
            'recentAlerts' => $recentAlerts,
            'recentJams' => $recentJams,
            'superAdminData' => $superAdminData,
        ]);
    }

    // ─── Método auxiliar para tamanho do banco ──────────────────────────

    private function getDatabaseSize(): float
    {
        try {
            $sql = "SELECT SUM(data_length + index_length) / 1024 / 1024 AS size_mb
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()";
            $result = $this->entityManager->getConnection()->executeQuery($sql)->fetchOne();
            return (float) $result;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
