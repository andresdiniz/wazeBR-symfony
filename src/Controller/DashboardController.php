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
use App\Repository\WazeCountRepository;
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
        private WazeCountRepository $wazeCountRepo,
    ) {}

    #[Route('/', name: 'dashboard_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $partner = $user->getPartner();

        if (!$partner) {
            return new Response('<div class="p-5 text-center text-white bg-dark">Usuário sem parceiro vinculado.</div>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $partnerId = $partner->getId();
        $partnerLabel = mb_convert_encoding($partner->getName() ?? $partner->getSlug() ?? 'Parceiro #' . $partnerId, 'UTF-8', 'UTF-8');

        $now = time() * 1000;
        $lastHour = $now - 3600 * 1000;
        $lastDay = $now - 24 * 3600 * 1000;
        $lastWeek = $now - 7 * 24 * 3600 * 1000;

        $conn = $this->alertRepo->getEntityManager()->getConnection();

        // Agregados de alerts e jams
        $alertsStats = $this->alertRepo->createQueryBuilder('a')
            ->select(
                'COUNT(a.id) as total',
                'SUM(CASE WHEN a.pubMillis >= :lastHour THEN 1 ELSE 0 END) as lastHour',
                'SUM(CASE WHEN a.pubMillis >= :lastDay THEN 1 ELSE 0 END) as lastDay',
                'SUM(CASE WHEN a.pubMillis >= :lastWeek THEN 1 ELSE 0 END) as lastWeek'
            )
            ->setParameter('lastHour', $lastHour)
            ->setParameter('lastDay', $lastDay)
            ->setParameter('lastWeek', $lastWeek)
            ->getQuery()
            ->getArrayResult()[0] ?? [];

        $jamsStats = $this->jamRepo->createQueryBuilder('j')
            ->select(
                'COUNT(j.id) as total',
                'SUM(CASE WHEN j.pubMillis >= :lastDay THEN 1 ELSE 0 END) as lastDay',
                'SUM(CASE WHEN j.pubMillis >= :lastWeek THEN 1 ELSE 0 END) as lastWeek'
            )
            ->setParameter('lastDay', $lastDay)
            ->setParameter('lastWeek', $lastWeek)
            ->getQuery()
            ->getArrayResult()[0] ?? [];

        // Links e rotas
        $links = $this->linkRepo->createQueryBuilder('l')
            ->select('l.id, l.url')
            ->getQuery()
            ->getArrayResult();

        $routes = $this->tvtRouteRepo->createQueryBuilder('r')
            ->select('r.id, r.name, r.length, r.time, r.jamLevel, r.isSubRoute')
            ->setMaxResults(20)
            ->getQuery()
            ->getArrayResult();

        $irregsCount = $this->irregRepo->createQueryBuilder('i')
            ->select('COUNT(i.id) as total')
            ->getQuery()
            ->getSingleScalarResult();

        $cifsCount = $this->cifsRepo->createQueryBuilder('c')
            ->select('COUNT(c.id) as total')
            ->getQuery()
            ->getSingleScalarResult();

        // Qualidade dos alertas
        $qualityStats = $conn->fetchAssociative(<<<'SQL'
            SELECT
                AVG(a.reliability) AS avg_reliability,
                AVG(a.confidence) AS avg_confidence
            FROM waze_alerts a
            WHERE a.pub_millis >= :lastDay
              AND a.reliability IS NOT NULL
              AND a.confidence IS NOT NULL
        SQL, ['lastDay' => $lastDay]);

        $avgReliability = (float)($qualityStats['avg_reliability'] ?? 0);
        $avgConfidence  = (float)($qualityStats['avg_confidence'] ?? 0);

        // WazeCount Real
        $latestWazeCount = $this->wazeCountRepo->findLatest($partner);
        $sameTimeLastWeekWazeCount = $this->wazeCountRepo->findSameTimeLastWeek($partner);
        $peakWazeCount = $this->wazeCountRepo->peakOfDay($partner);

        $wazeCountThisWeek = $latestWazeCount ? (int)$latestWazeCount->getWazersTotal() : null;
        $wazeCountLastWeek = $sameTimeLastWeekWazeCount ? (int)$sameTimeLastWeekWazeCount->getWazersTotal() : null;
        $wazeCountPeak = $peakWazeCount;

        // KPIs
        $kpis = [
            'alerts' => (int)($alertsStats['total'] ?? 0),
            'jams' => (int)($jamsStats['total'] ?? 0),
            'cemaden' => 0,
            'cities' => 0,
            'links' => count($links),
            'routes' => count($routes),
            'irregularities' => (int)($irregsCount ?? 0),
            'alerts1h' => (int)($alertsStats['lastHour'] ?? 0),
            'alerts24h' => (int)($alertsStats['lastDay'] ?? 0),
            'alerts7d' => (int)($alertsStats['lastWeek'] ?? 0),
            'jams24h' => (int)($jamsStats['lastDay'] ?? 0),
            'jams7d' => (int)($jamsStats['lastWeek'] ?? 0),
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
            'cifsActiveCount' => (int)($cifsCount ?? 0),
            'avgReliability' => $avgReliability,
            'avgConfidence' => $avgConfidence,
            'wazeCount' => $wazeCountThisWeek,
            'wazeCountLastWeek' => $wazeCountLastWeek,
            'wazeCountPeak' => $wazeCountPeak,
        ];

        // Alertas por tipo
        $alertsByTypeRaw = $this->alertRepo->createQueryBuilder('a')
            ->select('a.type, a.subtype, COUNT(a.id) as total')
            ->where('a.pubMillis >= :lastDay')
            ->setParameter('lastDay', $lastDay)
            ->groupBy('a.type', 'a.subtype')
            ->getQuery()
            ->getArrayResult();

        $alertsByType = [];
        foreach ($alertsByTypeRaw as $row) {
            $alertsByType[] = [
                'type' => $row['type'],
                'subtype' => $row['subtype'],
                'total' => (int)$row['total'],
            ];
        }

        // Jams por nível
        $jamsByLevelRaw = $this->jamRepo->createQueryBuilder('j')
            ->select('j.level, COUNT(j.id) as total')
            ->where('j.pubMillis >= :lastDay')
            ->setParameter('lastDay', $lastDay)
            ->groupBy('j.level')
            ->orderBy('j.level', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $jamsByLevel = [];
        for ($i = 0; $i <= 5; $i++) {
            $jamsByLevel[] = [
                'level' => $i,
                'total' => 0,
            ];
        }
        foreach ($jamsByLevelRaw as $row) {
            $lvl = (int)$row['level'];
            if ($lvl >= 0 && $lvl <= 5) {
                $jamsByLevel[$lvl]['total'] = (int)$row['total'];
            }
        }

        $typesMap = [
            'ACCIDENT' => 'Acidente',
            'JAM' => 'Congestionamento',
            'MISC' => 'Diversos',
            'CONSTRUCTION' => 'Obra',
            'ROAD_CLOSED' => 'Via Interditada',
            'HAZARD' => 'Perigo',
            'WEATHERHAZARD' => 'Perigo climático',
        ];

        $subtypesMap = [
            'ACCIDENT' => 'Acidente',
            'HAZARD_ON_ROAD' => 'Perigo na via',
            'HAZARD_ON_SHOULDER' => 'Perigo no acostamento',
            'HAZARD_WEATHER' => 'Perigo climático',
            'ROAD_CLOSED_CONSTRUCTION' => 'Obra',
            'ROAD_CLOSED_ACCIDENT' => 'Acidente',
            'JAM_STAND_TRAFFIC' => 'Trânsito parado',
            'JAM_HEAVY_TRAFFIC' => 'Trânsito intenso',
            'MISC' => 'Diversos',
        ];

        // 24h hourly buckets (horas reais formatadas HH:00)
        $getHourlyBuckets = function(string $tableName) use ($conn, $lastDay) {
            $buckets = [];
            $now = new \DateTimeImmutable();
            for ($i = 23; $i >= 0; $i--) {
                $t = $now->modify("-{$i} hours");
                $key = $t->format('Y-m-d H:00:00');
                $label = $t->format('H:00');
                $buckets[$key] = ['hour_label' => $label, 'total' => 0];
            }
            $sql = "
                SELECT 
                    DATE_FORMAT(FROM_UNIXTIME(pub_millis / 1000), '%Y-%m-%d %H:00:00') as bucket,
                    COUNT(id) as total
                FROM {$tableName}
                WHERE pub_millis >= :lastDay
                GROUP BY bucket
            ";
            $res = $conn->fetchAllAssociative($sql, ['lastDay' => $lastDay]);
            foreach ($res as $row) {
                if (isset($buckets[$row['bucket']])) {
                    $buckets[$row['bucket']]['total'] = (int)$row['total'];
                }
            }
            return array_values($buckets);
        };

        $alertsPerHourRaw = $getHourlyBuckets('waze_alerts');
        $jamsPerHourRaw = $getHourlyBuckets('waze_traffic_jams');

        $alertsPerHourLabels = array_column($alertsPerHourRaw, 'hour_label');
        $alertsPerHourData = array_map('intval', array_column($alertsPerHourRaw, 'total'));

        $jamsPerHourLabels = array_column($jamsPerHourRaw, 'hour_label');
        $jamsPerHourData = array_map('intval', array_column($jamsPerHourRaw, 'total'));

        // Recent alerts
        $recentAlertsRaw = $this->alertRepo->createQueryBuilder('a')
            ->select('a.id, a.type, a.subtype, a.city, a.street, a.pubMillis, a.latitude, a.longitude')
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults(15)
            ->getQuery()
            ->getArrayResult();

        $recentAlerts = array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'type' => $r['type'],
                'subtype' => $r['subtype'],
                'city' => $r['city'],
                'street' => $r['street'],
                'pubMillis' => (int)$r['pubMillis'],
                'latitude' => (float)$r['latitude'],
                'longitude' => (float)$r['longitude'],
            ];
        }, $recentAlertsRaw);

        // Recent jams
        $recentJamsRaw = $conn->fetchAllAssociative(<<<'SQL'
            SELECT
                j.id,
                j.level,
                j.city,
                j.street,
                j.pub_millis,
                j.line,
                j.segments
            FROM waze_traffic_jams j
            WHERE j.line IS NOT NULL
              AND j.line != ''
              AND j.line != '[]'
            ORDER BY j.pub_millis DESC
            LIMIT 15
        SQL, []);

        $recentJams = array_map(function ($r) {
            $lineStr = $r['line'];
            $segmentsStr = $r['segments'];
            $coords = [];
            if (is_string($lineStr) && strlen($lineStr) > 2) {
                $decoded = json_decode($lineStr, true);
                if (is_array($decoded) && !empty($decoded)) {
                    if (isset($decoded[0]['x']) && isset($decoded[0]['y'])) {
                        $coords = $decoded;
                    } elseif (is_array($decoded[0]) && count($decoded[0]) === 2) {
                        $coords = $decoded;
                    }
                }
            }
            if (empty($coords) && is_string($segmentsStr) && strlen($segmentsStr) > 2) {
                $decodedSegments = json_decode($segmentsStr, true);
                if (is_array($decodedSegments) && !empty($decodedSegments)) {
                    foreach ($decodedSegments as $seg) {
                        if (isset($seg['x']) && isset($seg['y'])) {
                            $coords[] = ['x' => $seg['x'], 'y' => $seg['y']];
                        }
                    }
                }
            }
            return [
                'id' => (int)$r['id'],
                'level' => (int)$r['level'],
                'city' => $r['city'],
                'street' => $r['street'],
                'pubMillis' => (int)$r['pub_millis'],
                'line' => $coords,
            ];
        }, $recentJamsRaw);

        // Irregularidades
        $irregJamsRaw = $conn->fetchAllAssociative(<<<'SQL'
            SELECT
                j.id,
                j.street,
                j.city,
                j.level,
                j.delay,
                j.speed_kmh,
                j.pub_millis
            FROM waze_traffic_jams j
            WHERE j.pub_millis >= :lastDay
              AND j.level >= 2
            ORDER BY j.delay DESC
            LIMIT 10
        SQL, ['lastDay' => $lastDay]);

        $irregRecentList = array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'street' => $r['street'],
                'city' => $r['city'],
                'level' => (int)$r['level'],
                'delay' => (int)$r['delay'],
                'speed_kmh' => (float)($r['speed_kmh'] ?? 0),
            ];
        }, $irregJamsRaw);

        $speedLossRaw = $conn->fetchAllAssociative(<<<'SQL'
            SELECT
                j.street,
                j.city,
                j.speed_kmh,
                60.0 - j.speed_kmh AS loss
            FROM waze_traffic_jams j
            WHERE j.pub_millis >= :lastDay
              AND j.speed_kmh IS NOT NULL
            ORDER BY loss DESC
            LIMIT 5
        SQL, ['lastDay' => $lastDay]);

        $irregSpeedLoss = array_map(function ($r) {
            return [
                'street' => $r['street'],
                'city' => $r['city'],
                'loss' => (float)$r['loss'],
            ];
        }, $speedLossRaw);

        $delayByStreetRaw = $conn->fetchAllAssociative(<<<'SQL'
            SELECT
                j.street,
                j.city,
                SUM(j.delay) AS total_delay
            FROM waze_traffic_jams j
            WHERE j.pub_millis >= :lastDay
              AND j.delay IS NOT NULL
            GROUP BY j.street, j.city
            ORDER BY total_delay DESC
            LIMIT 5
        SQL, ['lastDay' => $lastDay]);

        $irregDelayByStreet = array_map(function ($r) {
            return [
                'street' => $r['street'],
                'city' => $r['city'],
                'delay' => (int)$r['total_delay'],
            ];
        }, $delayByStreetRaw);

        $avgAccumulatedDelay = 0;
        if (!empty($delayByStreetRaw)) {
            $sumDelays = array_sum(array_column($delayByStreetRaw, 'total_delay'));
            $avgAccumulatedDelay = round($sumDelays / count($delayByStreetRaw), 1);
        }

        $response = $this->render('dashboard/index.html.twig', [
            'partner' => $partner,
            'partnerLabel' => $partnerLabel,
            'kpis' => $kpis,
            'alertsByType' => $alertsByType,
            'jamsByLevel' => $jamsByLevel,
            'typesMap' => $typesMap,
            'subtypesMap' => $subtypesMap,
            'irregSpeedLoss' => $irregSpeedLoss,
            'irregDelayByStreet' => $irregDelayByStreet,
            'avgAccumulatedDelay' => $avgAccumulatedDelay,
            'irregRecentList' => $irregRecentList,
            'alertsPerHourLabels' => $alertsPerHourLabels,
            'alertsPerHourData' => $alertsPerHourData,
            'jamsPerHourLabels' => $jamsPerHourLabels,
            'jamsPerHourData' => $jamsPerHourData,
            'recentAlerts' => $recentAlerts,
            'recentJams' => $recentJams,
            'routes' => $routes,
            'links' => $links,
        ]);

        $response->setSharedMaxAge(300);
        $response->setPrivate();
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        return $response;
    }
}
