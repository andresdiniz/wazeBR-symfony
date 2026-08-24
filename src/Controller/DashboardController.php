<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\WazeTvtRouteRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\MonitoredCityRepository;
use App\Repository\WazeIrregularityRepository;
use App\Repository\CifsEventRepository;
use App\Repository\WazeAlertTypeRepository;
use App\Repository\CemadenDataRepository;
use App\Repository\CemadenHydroDataRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

#[Route('/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    private const PERIODS = [
        'today'      => ['label' => 'Hoje (1h)', 'hours' => 1],
        'yesterday'  => ['label' => 'Ontem',    'hours' => null],
        'week'       => ['label' => '7 dias',   'hours' => 24 * 7],
        'month'      => ['label' => '30 dias',  'hours' => 24 * 30],
        'six_months' => ['label' => '6 meses',  'hours' => 24 * 182],
        'year'       => ['label' => '1 ano',    'hours' => 24 * 365],
    ];

    private const DEFAULT_PERIOD = 'week';

    private const LEVEL_LABELS = [
        0 => 'Livre', 1 => 'Lento', 2 => 'Moderado',
        3 => 'Pesado', 4 => 'Muito Pesado', 5 => 'Parado',
    ];

    // Limite para o mapa: últimos 100 de cada (jams e alerts)
    private const MAP_LIMIT = 100;

    public function __construct(
        private PartnerRepository $partnerRepo,
        private WazeAlertRepository $alertRepo,
        private WazeTrafficJamRepository $jamRepo,
        private WazeTvtRouteRepository $tvtRouteRepo,
        private MonitoredLinkRepository $linkRepo,
        private MonitoredCityRepository $cityRepo,
        private WazeIrregularityRepository $irregRepo,
        private CifsEventRepository $cifsRepo,
        private WazeAlertTypeRepository $alertTypeRepo,
        private CemadenDataRepository $cemadenDataRepo,
        private CemadenHydroDataRepository $cemadenHydroRepo,
        private CacheInterface $cache,
    ) {}

    #[Route('/', name: 'dashboard_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $partner = $user?->getPartner();

        if (!$partner) {
            return new Response('<div class="p-5 text-center">Usuário sem parceiro vinculado.</div>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $partnerId = $partner->getId();
        $partnerLabel = $partner->getName() ?: $partnerId;
        $periodKey = $request->query->get('period', self::DEFAULT_PERIOD);
        if (!isset(self::PERIODS[$periodKey])) {
            $periodKey = self::DEFAULT_PERIOD;
        }
        [$from, $to] = $this->resolvePeriodRange($periodKey);

        // ---- Dados com cache (5 minutos) ----
        $cacheKey = 'dashboard_' . $partnerId . '_' . $periodKey;
        $data = $this->cache->get($cacheKey, function() use ($partner, $partnerId, $from, $to) {
            $liveNow = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
            $last24hFrom = $liveNow->modify('-24 hours');

            // Totais globais
            $alertsCount = (int) $this->alertRepo->createQueryBuilder('a')
                ->select('COUNT(a.id)')->where('a.partner = :partnerId')
                ->setParameter('partnerId', $partnerId)->getQuery()->getSingleScalarResult();

            $jamsCount = (int) $this->jamRepo->createQueryBuilder('j')
                ->select('COUNT(j.id)')->where('j.partner = :partnerId')
                ->setParameter('partnerId', $partnerId)->getQuery()->getSingleScalarResult();

            $tvtRoutesCount = (int) $this->tvtRouteRepo->createQueryBuilder('r')
                ->select('COUNT(DISTINCT r.wazeRouteId)')
                ->innerJoin('r.snapshot', 's')
                ->where('s.partner = :partnerId')
                ->andWhere('r.isSubRoute = false')
                ->andWhere('r.wazeRouteId IS NOT NULL')
                ->andWhere('r.wazeRouteId != :emptyRouteId')
                ->setParameter('partnerId', $partnerId)
                ->setParameter('emptyRouteId', '')
                ->getQuery()->getSingleScalarResult();

            $monitoredLinksCount = $this->linkRepo->countByPartner($partner);
            $irregularitiesCount = $this->irregRepo->countByPartner($partner);
            $cifsEventsCount = (int) $this->cifsRepo->createQueryBuilder('e')
                ->select('COUNT(e.id)')->where('e.partner = :partnerId')
                ->setParameter('partnerId', $partnerId)->getQuery()->getSingleScalarResult();

            // Totais no período
            $periodAlerts = $this->alertRepo->countInPeriod($partner, $from, $to);
            $periodJams   = $this->jamRepo->countInPeriod($partner, $from, $to);
            $periodTvt    = (int) $this->tvtRouteRepo->createQueryBuilder('r')
                ->select('COUNT(DISTINCT r.wazeRouteId)')
                ->innerJoin('r.snapshot', 's')
                ->where('s.partner = :partnerId')
                ->andWhere('s.collectedAt BETWEEN :from AND :to')
                ->andWhere('r.isSubRoute = false')
                ->andWhere('r.wazeRouteId IS NOT NULL')
                ->andWhere('r.wazeRouteId != :emptyRouteId')
                ->setParameter('partnerId', $partnerId)
                ->setParameter('from', $from)
                ->setParameter('to', $to)
                ->setParameter('emptyRouteId', '')
                ->getQuery()->getSingleScalarResult();
            $periodIrreg  = $this->irregRepo->countInPeriod($partner, $from, $to);
            $periodCifs   = $this->cifsRepo->countInPeriod($partner, $from, $to);

            // Hero (live)
            $jamsLive = $this->jamRepo->liveSnapshot($partner, 3);
            $jamsBaseline = $this->jamRepo->historicalBaseline($partner);
            $cemadenReadings = $this->cemadenDataRepo->countByPartner($partner) + $this->cemadenHydroRepo->countByPartner($partner);
            $cemadenCities = $this->cemadenDataRepo->countDistinctMunicipalities($partner) + $this->cemadenHydroRepo->countDistinctMunicipalities($partner);

            // Gráficos
            $subtypeLabels = $this->alertTypeRepo->getSubtypesMap('pt');
            $typeLabels = $this->alertTypeRepo->getTypesMap('pt');
            $alertsBySubtypeRaw = $this->alertRepo->countBySubtypeInPeriod($partner, $from, $to, 10);
            $alertsBySubtype = array_map(function (array $r) use ($subtypeLabels, $typeLabels) {
                $key = $r['type'] . '|' . $r['subtype'];
                $label = $r['subtype'] ? ($subtypeLabels[$key] ?? $r['subtype']) : ($typeLabels[$r['type']] ?? $r['type']);
                return ['label' => $label, 'total' => (int)$r['total']];
            }, $alertsBySubtypeRaw);
            $totalAlertsInPeriod = array_sum(array_column($alertsBySubtype, 'total'));

            $jamsByLevel = $this->jamRepo->countByLevelInPeriod($partner, $from, $to);
            $topStreets = $this->jamRepo->topStreetsInPeriod($partner, $from, $to, 10);

            // ---- MAPA: ÚLTIMOS 100 JAMS E 100 ALERTS ----
            $mapJamsRaw = $this->jamRepo->createQueryBuilder('j')
                ->select('j.street, j.city, j.level, j.speedKmh, j.delay, j.line, j.pubMillis')
                ->where('j.partner = :partnerId')
                ->andWhere('j.pubMillis BETWEEN :fromMillis AND :toMillis')
                ->setParameter('partnerId', $partnerId)
                ->setParameter('fromMillis', $from->getTimestamp() * 1000)
                ->setParameter('toMillis', $to->getTimestamp() * 1000)
                ->orderBy('j.pubMillis', 'DESC')
                ->setMaxResults(self::MAP_LIMIT)
                ->getQuery()
                ->getArrayResult();

            // Simplificar e arredondar linhas
            $mapJams = array_map(function (array $j) {
                $line = $j['line'] ?? [];
                if (is_array($line) && count($line) > 0) {
                    $simplified = [];
                    $step = max(1, (int)(count($line) / 50));
                    foreach ($line as $idx => $point) {
                        if ($idx % $step === 0) {
                            $lat = isset($point['y']) ? (float)$point['y'] : (isset($point['lat']) ? (float)$point['lat'] : 0);
                            $lng = isset($point['x']) ? (float)$point['x'] : (isset($point['lng']) ? (float)$point['lng'] : 0);
                            if ($lat && $lng) {
                                $simplified[] = ['lat' => round($lat, 5), 'lng' => round($lng, 5)];
                            }
                        }
                    }
                    if (count($simplified) < 2 && count($line) >= 2) {
                        $first = reset($line);
                        $last = end($line);
                        $simplified = [
                            ['lat' => round($first['y'] ?? $first['lat'] ?? 0, 5), 'lng' => round($first['x'] ?? $first['lng'] ?? 0, 5)],
                            ['lat' => round($last['y'] ?? $last['lat'] ?? 0, 5), 'lng' => round($last['x'] ?? $last['lng'] ?? 0, 5)],
                        ];
                    }
                    $j['line'] = $simplified;
                } else {
                    $j['line'] = [];
                }
                return [
                    'street' => $j['street'] ?? '',
                    'city'   => $j['city'] ?? '',
                    'level'  => (int)($j['level'] ?? 0),
                    'speed'  => (int)($j['speedKmh'] ?? 0),
                    'delay'  => (int)($j['delay'] ?? 0),
                    'line'   => $j['line'],
                ];
            }, $mapJamsRaw);

            $mapAlertsRaw = $this->alertRepo->createQueryBuilder('a')
                ->select('a.type, a.subtype, a.latitude, a.longitude, a.street, a.city, a.pubMillis')
                ->where('a.partner = :partnerId')
                ->andWhere('a.pubMillis BETWEEN :fromMillis AND :toMillis')
                ->setParameter('partnerId', $partnerId)
                ->setParameter('fromMillis', $from->getTimestamp() * 1000)
                ->setParameter('toMillis', $to->getTimestamp() * 1000)
                ->orderBy('a.pubMillis', 'DESC')
                ->setMaxResults(self::MAP_LIMIT)
                ->getQuery()
                ->getArrayResult();

            $mapAlerts = array_map(function (array $a) use ($subtypeLabels, $typeLabels) {
                $key = $a['type'] . '|' . $a['subtype'];
                $label = $a['subtype'] ? ($subtypeLabels[$key] ?? $a['subtype']) : ($typeLabels[$a['type']] ?? $a['type']);
                return [
                    'lat'    => round((float)($a['latitude'] ?? 0), 5),
                    'lng'    => round((float)($a['longitude'] ?? 0), 5),
                    'type'   => $a['type'],
                    'label'  => $label,
                    'street' => $a['street'] ?? '',
                    'city'   => $a['city'] ?? '',
                ];
            }, $mapAlertsRaw);

            // Verificar truncamento (se total > limite)
            $totalJamsInPeriod = $this->jamRepo->countInPeriod($partner, $from, $to);
            $totalAlertsInPeriod = $this->alertRepo->countInPeriod($partner, $from, $to);
            $mapJamsTruncated = $totalJamsInPeriod > self::MAP_LIMIT;
            $mapAlertsTruncated = $totalAlertsInPeriod > self::MAP_LIMIT;

            // Recentes
            $recentAlertsRaw = $this->alertRepo->createQueryBuilder('a')
                ->select('a.id, a.type, a.subtype, a.city, a.street, a.pubMillis')
                ->where('a.partner = :partnerId')->andWhere('a.pubMillis BETWEEN :from AND :to')
                ->setParameter('partnerId', $partnerId)->setParameter('from', $from->getTimestamp() * 1000)->setParameter('to', $to->getTimestamp() * 1000)
                ->orderBy('a.pubMillis', 'DESC')->setMaxResults(10)->getQuery()->getArrayResult();

            $recentAlerts = array_map(function (array $r) {
                $pubMillis = (int)($r['pubMillis'] ?? 0);
                $pubAt = $pubMillis > 0 ? (new \DateTimeImmutable('@' . intdiv($pubMillis, 1000)))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m H:i') : null;
                return ['id' => (int)$r['id'], 'type' => $r['type'], 'subtype' => $r['subtype'], 'city' => $r['city'], 'street' => $r['street'], 'pubMillis' => $pubMillis, 'pubAt' => $pubAt];
            }, $recentAlertsRaw);

            $recentJamsRaw = $this->jamRepo->createQueryBuilder('j')
                ->select('j.id, j.street, j.city, j.level, j.length, j.delay, j.speed, j.type, j.turnType, j.pubMillis')
                ->where('j.partner = :partnerId')->andWhere('j.pubMillis BETWEEN :from AND :to')
                ->setParameter('partnerId', $partnerId)->setParameter('from', $from->getTimestamp() * 1000)->setParameter('to', $to->getTimestamp() * 1000)
                ->orderBy('j.pubMillis', 'DESC')->setMaxResults(10)->getQuery()->getArrayResult();

            $recentJams = array_map(function (array $r) {
                $pubMillis = (int)($r['pubMillis'] ?? 0);
                $pubAt = $pubMillis > 0 ? (new \DateTimeImmutable('@' . intdiv($pubMillis, 1000)))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m H:i') : null;
                return ['id' => (int)$r['id'], 'street' => $r['street'], 'city' => $r['city'], 'level' => (int)($r['level'] ?? 0), 'length' => (int)($r['length'] ?? 0), 'delay' => (int)($r['delay'] ?? 0), 'speed' => (int)($r['speed'] ?? 0), 'type' => $r['type'], 'turnType' => $r['turnType'], 'pubMillis' => $pubMillis, 'pubAt' => $pubAt];
            }, $recentJamsRaw);

            return [
                'partnerStats' => [
                    'alerts' => $alertsCount,
                    'jams' => $jamsCount,
                    'tvtRoutes' => $tvtRoutesCount,
                    'monitoredLinks' => $monitoredLinksCount,
                    'irregularities' => $irregularitiesCount,
                    'cifsEvents' => $cifsEventsCount,
                ],
                'periodStats' => [
                    'alerts' => $periodAlerts,
                    'jams' => $periodJams,
                    'tvtRoutes' => $periodTvt,
                    'irregularities' => $periodIrreg,
                    'cifsEvents' => $periodCifs,
                ],
                'hero' => [
                    'alertsTotal' => $alertsCount,
                    'alertsLast24h' => $this->alertRepo->countInPeriod($partner, $last24hFrom, $liveNow),
                    'jamsTotal' => $jamsCount,
                    'jamsLast24h' => $this->jamRepo->countInPeriod($partner, $last24hFrom, $liveNow),
                    'jamsLiveTotal' => $jamsLive['total'],
                    'jamsLiveMaxLevel' => $jamsLive['maxLevel'],
                    'jamsLiveMaxLevelLabel' => $jamsLive['maxLevel'] !== null ? (self::LEVEL_LABELS[$jamsLive['maxLevel']] ?? null) : null,
                    'avgSpeedLive' => $jamsLive['avgSpeedKmh'],
                    'avgSpeedHist' => $jamsBaseline['avgSpeedKmh'],
                    'avgDelayLive' => $jamsLive['avgDelaySec'],
                    'lengthLiveKm' => $jamsLive['lengthKm'],
                    'lengthHistKm' => $jamsBaseline['lengthKm'],
                    'routesMonitored' => $tvtRoutesCount,
                    'monitoredLinks' => $monitoredLinksCount,
                    'monitoredCities' => $this->cityRepo->countByPartner($partner),
                    'cemadenReadings' => $cemadenReadings,
                    'cemadenCities' => $cemadenCities,
                ],
                'alertsBySubtype' => $alertsBySubtype,
                'totalAlertsInPeriod' => $totalAlertsInPeriod,
                'jamsByLevel' => $jamsByLevel,
                'topStreets' => $topStreets,
                'mapJams' => $mapJams,
                'mapAlerts' => $mapAlerts,
                'mapJamsTruncated' => $mapJamsTruncated,
                'mapAlertsTruncated' => $mapAlertsTruncated,
                'recentAlerts' => $recentAlerts,
                'recentJams' => $recentJams,
                'irregularities' => $this->irregRepo->findBy(['partner' => $partner], ['collectedAt' => 'DESC'], 10),
                'cifsEvents' => array_slice($this->cifsRepo->findBy(['partner' => $partner]), 0, 10),
                'alertTypes' => $this->alertTypeRepo->findAll(),
            ];
        });

        // Extrai dados para a view
        $partnerStats = $data['partnerStats'];
        $periodStats  = $data['periodStats'];
        $hero         = $data['hero'];
        $alertsBySubtype = $data['alertsBySubtype'];
        $totalAlertsInPeriod = $data['totalAlertsInPeriod'];
        $jamsByLevel   = $data['jamsByLevel'];
        $topStreets    = $data['topStreets'];
        $mapJams       = $data['mapJams'];
        $mapAlerts     = $data['mapAlerts'];
        $mapJamsTruncated  = $data['mapJamsTruncated'];
        $mapAlertsTruncated = $data['mapAlertsTruncated'];
        $recentAlerts  = $data['recentAlerts'];
        $recentJams    = $data['recentJams'];
        $irregularities = $data['irregularities'];
        $cifsEvents    = $data['cifsEvents'];
        $alertTypes    = $data['alertTypes'];

        return $this->render('dashboard/index.html.twig', [
            'partnerLabel' => $partnerLabel,
            'partnerStats' => $partnerStats,
            'periodStats'  => $periodStats,
            'periods'      => self::PERIODS,
            'periodKey'    => $periodKey,
            'periodFrom'   => $from,
            'periodTo'     => $to,
            'hero'         => $hero,
            'alertsBySubtype' => $alertsBySubtype,
            'totalAlertsInPeriod' => $totalAlertsInPeriod,
            'jamsByLevel'  => $jamsByLevel,
            'topStreets'   => $topStreets,
            'mapJams'      => $mapJams,
            'mapAlerts'    => $mapAlerts,
            'mapJamsTruncated' => $mapJamsTruncated,
            'mapAlertsTruncated' => $mapAlertsTruncated,
            'recentAlerts' => $recentAlerts,
            'recentJams'   => $recentJams,
            'irregularities' => $irregularities,
            'cifsEvents'   => $cifsEvents,
            'alertTypes'   => $alertTypes,
            'tvtRoutesCount' => $partnerStats['tvtRoutes'],
            'monitoredLinksCount' => $partnerStats['monitoredLinks'],
        ]);
    }

    private function resolvePeriodRange(string $periodKey): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        if ($periodKey === 'yesterday') {
            $yesterday = $now->modify('-1 day');
            return [$yesterday->setTime(0, 0, 0), $yesterday->setTime(23, 59, 59)];
        }
        $hours = self::PERIODS[$periodKey]['hours'] ?? self::PERIODS[self::DEFAULT_PERIOD]['hours'];
        return [$now->modify("-{$hours} hours"), $now];
    }
}
