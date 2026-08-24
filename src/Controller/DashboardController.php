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

#[Route('/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    private const PERIODS = [
        'now' => ['label' => 'Agora (1h)', 'hours' => 1],
        'yesterday' => ['label' => 'Ontem', 'hours' => null],
        'week' => ['label' => '7 dias', 'hours' => 24 * 7],
        'month' => ['label' => '30 dias', 'hours' => 24 * 30],
        'six_months' => ['label' => '6 meses', 'hours' => 24 * 182],
        'year' => ['label' => '1 ano', 'hours' => 24 * 365],
    ];

    private const DEFAULT_PERIOD = 'week';

    private const LEVEL_LABELS = [
        0 => 'Livre', 1 => 'Lento', 2 => 'Moderado',
        3 => 'Pesado', 4 => 'Muito Pesado', 5 => 'Parado',
    ];

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
    ) {}

    #[Route('/', name: 'dashboard_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $partner = $user?->getPartner();

        if (!$partner) {
            return new Response('<div class="p-5 text-center">Usu&aacute;rio sem parceiro vinculado.</div>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $partnerId = $partner->getId();
        $partnerLabel = $partner->getName() ?: $partnerId;
        $periodKey = $request->query->get('period', self::DEFAULT_PERIOD);
        if (!isset(self::PERIODS[$periodKey])) $periodKey = self::DEFAULT_PERIOD;
        [$from, $to] = $this->resolvePeriodRange($periodKey);

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

        $tvtRoutesPeriodCount = (int) $this->tvtRouteRepo->createQueryBuilder('r')
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

        $monitoredLinksCount = $this->linkRepo->countByPartner($partner);
        $irregularitiesCount = $this->irregRepo->countByPartner($partner);
        $cifsEventsCount = (int) $this->cifsRepo->createQueryBuilder('e')
            ->select('COUNT(e.id)')->where('e.partner = :partnerId')
            ->setParameter('partnerId', $partnerId)->getQuery()->getSingleScalarResult();

        $partnerStats = [
            'alerts' => $alertsCount, 'jams' => $jamsCount,
            'tvtRoutes' => $tvtRoutesCount, 'monitoredLinks' => $monitoredLinksCount,
            'irregularities' => $irregularitiesCount, 'cifsEvents' => $cifsEventsCount,
        ];

        $periodStats = [
            'alerts' => $this->alertRepo->countInPeriod($partner, $from, $to),
            'jams' => $this->jamRepo->countInPeriod($partner, $from, $to),
            'tvtRoutes' => $tvtRoutesPeriodCount,
            'irregularities' => $this->irregRepo->countInPeriod($partner, $from, $to),
            'cifsEvents' => $this->cifsRepo->countInPeriod($partner, $from, $to),
        ];

        $liveNow = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $last24hFrom = $liveNow->modify('-24 hours');
        $jamsLive = $this->jamRepo->liveSnapshot($partner, 3);
        $jamsBaseline = $this->jamRepo->historicalBaseline($partner);
        $cemadenReadings = $this->cemadenDataRepo->countByPartner($partner) + $this->cemadenHydroRepo->countByPartner($partner);
        $cemadenCities = $this->cemadenDataRepo->countDistinctMunicipalities($partner) + $this->cemadenHydroRepo->countDistinctMunicipalities($partner);

        $hero = [
            'alertsTotal' => $alertsCount, 'alertsLast24h' => $this->alertRepo->countInPeriod($partner, $last24hFrom, $liveNow),
            'jamsTotal' => $jamsCount, 'jamsLast24h' => $this->jamRepo->countInPeriod($partner, $last24hFrom, $liveNow),
            'jamsLiveTotal' => $jamsLive['total'], 'jamsLiveMaxLevel' => $jamsLive['maxLevel'],
            'jamsLiveMaxLevelLabel' => $jamsLive['maxLevel'] !== null ? (self::LEVEL_LABELS[$jamsLive['maxLevel']] ?? null) : null,
            'avgSpeedLive' => $jamsLive['avgSpeedKmh'], 'avgSpeedHist' => $jamsBaseline['avgSpeedKmh'],
            'avgDelayLive' => $jamsLive['avgDelaySec'], 'lengthLiveKm' => $jamsLive['lengthKm'],
            'lengthHistKm' => $jamsBaseline['lengthKm'], 'routesMonitored' => $tvtRoutesCount,
            'monitoredLinks' => $monitoredLinksCount, 'monitoredCities' => $this->cityRepo->countByPartner($partner),
            'cemadenReadings' => $cemadenReadings, 'cemadenCities' => $cemadenCities,
        ];

        $subtypeLabels = $this->alertTypeRepo->getSubtypesMap('pt');
        $typeLabels = $this->alertTypeRepo->getTypesMap('pt');
        $alertsBySubtypeRaw = $this->alertRepo->countBySubtypeInPeriod($partner, $from, $to, 10);
        $alertsBySubtype = array_map(function (array $r) use ($subtypeLabels, $typeLabels) {
            $key = $r['type'] . '|' . $r['subtype'];
            $label = $r['subtype'] ? ($subtypeLabels[$key] ?? $r['subtype']) : ($typeLabels[$r['type']] ?? $r['type']);
            return ['label' => $label, 'total' => $r['total']];
        }, $alertsBySubtypeRaw);

        $jamsByLevel = $this->jamRepo->countByLevelInPeriod($partner, $from, $to);
        $topStreets = $this->jamRepo->topStreetsInPeriod($partner, $from, $to, 10);
        $mapJamsRaw = $this->jamRepo->findForMapInPeriod($partner, $from, $to, 400);
        $mapJams = array_map(static fn(array $j) => [
            'street' => $j['street'], 'city' => $j['city'], 'level' => $j['level'],
            'speed' => $j['speedKmh'], 'delay' => $j['delay'], 'line' => $j['line'],
        ], $mapJamsRaw);

        $mapAlertsRaw = $this->alertRepo->findForMapInPeriod($partner, $from, $to, 600);
        $mapAlerts = array_map(function (array $a) use ($subtypeLabels, $typeLabels) {
            $key = $a['type'] . '|' . $a['subtype'];
            $label = $a['subtype'] ? ($subtypeLabels[$key] ?? $a['subtype']) : ($typeLabels[$a['type']] ?? $a['type']);
            return ['lat' => (float) $a['latitude'], 'lng' => (float) $a['longitude'], 'type' => $a['type'], 'label' => $label, 'street' => $a['street'], 'city' => $a['city']];
        }, $mapAlertsRaw);

        $irregularities = $this->irregRepo->findBy(['partner' => $partner], ['collectedAt' => 'DESC'], 10);
        $allCifs = $this->cifsRepo->findBy(['partner' => $partner]);
        $cifsEvents = array_slice($allCifs, 0, 10);
        $alertTypes = $this->alertTypeRepo->findAll();

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

        return $this->render('dashboard/index.html.twig', [
            'partnerLabel' => $partnerLabel, 'partnerStats' => $partnerStats, 'tvtRoutesCount' => $tvtRoutesCount,
            'monitoredLinksCount' => $monitoredLinksCount, 'irregularities' => $irregularities, 'cifsEvents' => $cifsEvents,
            'alertTypes' => $alertTypes, 'recentAlerts' => $recentAlerts, 'recentJams' => $recentJams, 'hero' => $hero,
            'periods' => self::PERIODS, 'periodKey' => $periodKey, 'periodStats' => $periodStats,
            'periodFrom' => $from, 'periodTo' => $to, 'alertsBySubtype' => $alertsBySubtype,
            'jamsByLevel' => $jamsByLevel, 'topStreets' => $topStreets, 'mapJams' => $mapJams,
            'mapAlerts' => $mapAlerts, 'mapJamsTruncated' => count($mapJamsRaw) >= 400,
            'mapAlertsTruncated' => count($mapAlertsRaw) >= 600,
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
