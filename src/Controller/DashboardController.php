<?php

namespace App\Controller;

use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
use App\Entity\WazeRoute;
use App\Entity\MonitoredLink;
use App\Entity\MonitoredCity;
use App\Entity\CifsEvent;
use App\Entity\WazeTvtRouteExecution;
use App\Entity\WazeTvtRoute;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\WazeRouteRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\MonitoredCityRepository;
use App\Repository\CifsEventRepository;
use App\Repository\WazeTvtRouteExecutionRepository;
use App\Repository\WazeTvtRouteRepository;
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

        // Contagens principais usando repositórios
        $alertsTotal = $this->countAlerts($periodFrom, $periodTo, $partnerId);
        $alertsLast24h = $this->countAlerts($last24Hours, $periodTo, $partnerId);
        $jamsTotal = $this->countJams($periodFrom, $periodTo, $partnerId);
        $jamsLast24h = $this->countJams($last24Hours, $periodTo, $partnerId);
        $jamsLiveTotal = $this->countJams($last3Hours, $periodTo, $partnerId);
        $routesTotal = $this->countRoutes($partnerId);
        $monitoredLinks = $this->countLinks($partnerId);
        $monitoredCities = $this->countCities($partnerId);
        $cifsEvents = $this->countCifsEvents($periodFrom, $periodTo, $partnerId);
        $executions = $this->countExecutions($periodFrom, $periodTo);
        $tvtRoutes = $this->countTvtRoutes();

        // Alertas recentes
        $recentAlerts = $this->alertRepository->findBy(
            ['partner' => $partnerId],
            ['createdAt' => 'DESC'],
            10
        );

        // Jams recentes
        $recentJams = $this->jamRepository->findBy(
            ['partner' => $partnerId],
            ['createdAt' => 'DESC'],
            10
        );

        // Dados para gráficos
        $jamsByLevel = $this->getJamsByLevel($periodFrom, $partnerId);
        $alertsBySubtype = $this->getAlertsBySubtype($periodFrom, $partnerId);
        $topStreets = $this->getTopStreets($periodFrom, $partnerId);

        // Dados do mapa
        $mapJams = $this->getMapJams($periodFrom, $partnerId);
        $mapAlerts = $this->getMapAlerts($periodFrom, $partnerId);

        $mapJamsTruncated = count($mapJams) >= 100;
        $mapAlertsTruncated = count($mapAlerts) >= 100;

        $jamsLiveMaxLevel = 0;
        foreach ($jamsByLevel as $jam) {
            if ((int) ($jam['level'] ?? 0) > $jamsLiveMaxLevel) {
                $jamsLiveMaxLevel = (int) ($jam['level'] ?? 0);
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
            'jamsLiveMaxLevelLabel' => $jamsLiveMaxLevel > 0
                ? 'Nível ' . $jamsLiveMaxLevel
                : null,
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

        // Debug data
        $debugData = [
            'database' => $this->entityManager->getConnection()->getDatabase(),
            'partnerId' => $partnerId,
            'period' => [
                'key' => $periodKey,
                'from' => $periodFrom->format(\DateTimeInterface::ATOM),
                'to' => $periodTo->format(\DateTimeInterface::ATOM),
            ],
            'counts' => [
                'alerts' => $alertsTotal,
                'jams' => $jamsTotal,
                'routes' => $routesTotal,
                'tvtRoutes' => $tvtRoutes,
                'executions' => $executions,
            ],
            'rows' => [
                'recentAlerts' => count($recentAlerts),
                'recentJams' => count($recentJams),
                'mapAlerts' => count($mapAlerts),
                'mapJams' => count($mapJams),
            ],
            'tables' => [
                'alerts_table' => $this->countTable('waze_alerts'),
                'jams_table' => $this->countTable('waze_traffic_jams'),
                'routes_table' => $this->countTable('waze_routes'),
            ],
        ];
        // Dados para o Super Admin
        $superAdminData = null;
        if (is_granted('ROLE_SUPER_ADMIN')) {
            $superAdminData = [
                'totalPartners' => 0, // Count de partners
                'totalUsers' => 0,    // Count de users
                'activeCrons' => 0,   // Status dos crons
                'systemLogs' => [],   // Logs do sistema
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
            'debugData' => $debugData,
            'superAdminData' => $superAdminData,
        ]);
    }

    private function countAlerts(\DateTimeInterface $from, \DateTimeInterface $to, ?int $partnerId): int
    {
        try {
            $qb = $this->alertRepository->createQueryBuilder('a');
            $qb->select('COUNT(a.id)')
                ->where('a.createdAt >= :from')
                ->andWhere('a.createdAt <= :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to);

            if ($partnerId) {
                $qb->andWhere('a.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countJams(\DateTimeInterface $from, \DateTimeInterface $to, ?int $partnerId): int
    {
        try {
            $qb = $this->jamRepository->createQueryBuilder('j');
            $qb->select('COUNT(j.id)')
                ->where('j.createdAt >= :from')
                ->andWhere('j.createdAt <= :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to);

            if ($partnerId) {
                $qb->andWhere('j.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countRoutes(?int $partnerId): int
    {
        try {
            $qb = $this->routeRepository->createQueryBuilder('r');
            $qb->select('COUNT(r.id)');

            if ($partnerId) {
                $qb->where('r.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countLinks(?int $partnerId): int
    {
        try {
            $qb = $this->linkRepository->createQueryBuilder('l');
            $qb->select('COUNT(l.id)');

            if ($partnerId) {
                $qb->where('l.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countCities(?int $partnerId): int
    {
        try {
            $qb = $this->cityRepository->createQueryBuilder('c');
            $qb->select('COUNT(c.id)');

            if ($partnerId) {
                $qb->where('c.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countCifsEvents(\DateTimeInterface $from, \DateTimeInterface $to, ?int $partnerId): int
    {
        try {
            $qb = $this->cifsRepository->createQueryBuilder('c');
            $qb->select('COUNT(c.id)')
                ->where('c.createdAt >= :from')
                ->andWhere('c.createdAt <= :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to);

            if ($partnerId) {
                $qb->andWhere('c.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countExecutions(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        try {
            $qb = $this->executionRepository->createQueryBuilder('e');
            $qb->select('COUNT(e.id)')
                ->where('e.createdAt >= :from')
                ->andWhere('e.createdAt <= :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to);

            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countTvtRoutes(): int
    {
        try {
            return (int) $this->tvtRouteRepository->count([]);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countTable(string $table): int
    {
        try {
            $sql = sprintf('SELECT COUNT(*) FROM `%s`', $table);
            return (int) $this->entityManager->getConnection()->executeQuery($sql)->fetchOne();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getJamsByLevel(\DateTimeInterface $from, ?int $partnerId): array
    {
        try {
            $qb = $this->jamRepository->createQueryBuilder('j');
            $qb->select('j.level AS level', 'COUNT(j.id) AS count')
                ->where('j.createdAt >= :from')
                ->setParameter('from', $from)
                ->groupBy('j.level')
                ->orderBy('j.level');

            if ($partnerId) {
                $qb->andWhere('j.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return $qb->getQuery()->getArrayResult();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getAlertsBySubtype(\DateTimeInterface $from, ?int $partnerId): array
    {
        try {
            $qb = $this->alertRepository->createQueryBuilder('a');
            $qb->select('a.subType AS label', 'COUNT(a.id) AS count')
                ->where('a.createdAt >= :from')
                ->setParameter('from', $from)
                ->groupBy('a.subType')
                ->orderBy('count', 'DESC');

            if ($partnerId) {
                $qb->andWhere('a.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return $qb->getQuery()->getArrayResult();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getTopStreets(\DateTimeInterface $from, ?int $partnerId): array
    {
        try {
            $qb = $this->jamRepository->createQueryBuilder('j');
            $qb->select('j.street AS street', 'COUNT(j.id) AS count')
                ->where('j.createdAt >= :from')
                ->setParameter('from', $from)
                ->groupBy('j.street')
                ->orderBy('count', 'DESC')
                ->setMaxResults(10);

            if ($partnerId) {
                $qb->andWhere('j.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return $qb->getQuery()->getArrayResult();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getMapJams(\DateTimeInterface $from, ?int $partnerId): array
    {
        try {
            $qb = $this->jamRepository->createQueryBuilder('j');
            $qb->select('j.id', 'j.street', 'j.city', 'j.level', 'j.latitude AS lat', 'j.longitude AS lng')
                ->where('j.createdAt >= :from')
                ->andWhere('j.latitude IS NOT NULL')
                ->andWhere('j.longitude IS NOT NULL')
                ->setParameter('from', $from)
                ->orderBy('j.createdAt', 'DESC')
                ->setMaxResults(100);

            if ($partnerId) {
                $qb->andWhere('j.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return $qb->getQuery()->getArrayResult();
        } catch (\Throwable) {
            return [];
        }
    }

    private function getMapAlerts(\DateTimeInterface $from, ?int $partnerId): array
    {
        try {
            $qb = $this->alertRepository->createQueryBuilder('a');
            $qb->select('a.id', 'a.type', 'a.street', 'a.latitude AS lat', 'a.longitude AS lng')
                ->where('a.createdAt >= :from')
                ->andWhere('a.latitude IS NOT NULL')
                ->andWhere('a.longitude IS NOT NULL')
                ->setParameter('from', $from)
                ->orderBy('a.createdAt', 'DESC')
                ->setMaxResults(100);

            if ($partnerId) {
                $qb->andWhere('a.partner = :partner')
                    ->setParameter('partner', $partnerId);
            }

            return $qb->getQuery()->getArrayResult();
        } catch (\Throwable) {
            return [];
        }
    }
}
