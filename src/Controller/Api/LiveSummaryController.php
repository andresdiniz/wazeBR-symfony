<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\CemadenDataRepository;
use App\Repository\MonitoredCityRepository;
use App\Repository\MonitoredLinkRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTvtRouteRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/live-summary', name: 'api_live_summary', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
class LiveSummaryController extends AbstractController
{
    public function __construct(
        private readonly TenantContext            $tenantContext,
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly CemadenDataRepository    $cemadenRepo,
        private readonly WazeTvtRouteRepository   $tvtRouteRepo,
        private readonly MonitoredCityRepository  $cityRepo,
        private readonly MonitoredLinkRepository  $linkRepo,
    ) {}

    public function __invoke(): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();

        // ── Jams ao vivo (últimas 3h) ────────────────────────────────────────
        $liveJams   = $this->jamRepo->findLiveByPartner($partner, 3);
        $liveStats  = $this->jamRepo->avgStats($partner, 3);
        $maxLevel   = count($liveJams) > 0
            ? max(array_map(fn($j) => $j->getLevel() ?? 0, $liveJams))
            : 0;

        // ── Alertas recentes (últimas 1h para feed) ─────────────────────────
        $recentAlerts = $this->alertRepo->findRecentByPartner($partner, 30);
        $alertsHour   = $this->alertRepo->countLast1hByPartner($partner);
        $alerts24h    = $this->alertRepo->countLast24hByPartner($partner);

        // ── CEMADEN ──────────────────────────────────────────────────────────
        $rainLastHour = $this->cemadenRepo->sumRainLastHourByPartner($partner);
        $cemadenData  = $this->cemadenRepo->findByPartner($partner);

        // ── Counters gerais ──────────────────────────────────────────────────
        $cityCount  = $this->cityRepo->countByPartner($partner);
        $linkCount  = $this->linkRepo->countByPartner($partner);
        $routeCount = $this->tvtRouteRepo->countByPartner($partner);

        // ── Feed de alertas serializado ──────────────────────────────────────
        $alertFeed = array_map(function ($a) {
            return [
                'id'        => $a->getId(),
                'type'      => $a->getType(),
                'subtype'   => $a->getSubtype(),
                'street'    => $a->getStreet() ?? $a->getRoadType() ?? '',
                'city'      => $a->getCity() ?? '',
                'lat'       => $a->getLatitude(),
                'lng'       => $a->getLongitude(),
                'confidence'=> $a->getReliability() ?? 0,
                'reportedAt'=> $a->getPubMillis()
                    ? (new \DateTimeImmutable('@' . intval($a->getPubMillis() / 1000)))->format('c')
                    : null,
            ];
        }, $recentAlerts);

        // ── Feed de jams serializado ─────────────────────────────────────────
        $jamFeed = array_map(function ($j) {
            return [
                'id'      => $j->getId(),
                'street'  => $j->getStreet() ?? '',
                'city'    => $j->getCity() ?? '',
                'level'   => $j->getLevel() ?? 0,
                'speed'   => $j->getSpeedKmh() ?? 0,
                'delay'   => $j->getDelaySeconds() ?? 0,
                'length'  => $j->getLengthM() ?? 0,
                'lat'     => $j->getStartNode() ? $j->getStartNode()['y'] ?? null : null,
                'lng'     => $j->getStartNode() ? $j->getStartNode()['x'] ?? null : null,
                'line'    => $j->getLine() ?? [],
            ];
        }, $liveJams);

        // ── CEMADEN serializado ──────────────────────────────────────────────
        $cemadenFeed = array_map(function ($c) {
            return [
                'station'  => $c->getStationName() ?? $c->getStationCode() ?? '',
                'city'     => $c->getCityName() ?? '',
                'state'    => $c->getState() ?? '',
                'rain'     => $c->getAccumulatedRain() ?? 0,
                'alertLevel' => $c->getAlertLevel() ?? 'NO_ALERT',
                'lat'      => $c->getLatitude(),
                'lng'      => $c->getLongitude(),
            ];
        }, $cemadenData);

        return $this->json([
            'ts'          => (new \DateTimeImmutable())->format('c'),
            'kpis' => [
                'liveJams'      => count($liveJams),
                'maxJamLevel'   => $maxLevel,
                'liveAvgSpeed'  => $liveStats['avgSpeed'],
                'liveAvgDelay'  => $liveStats['avgDelay'],
                'liveTotalLen'  => $liveStats['totalLength'],
                'alertsHour'    => $alertsHour,
                'alerts24h'     => $alerts24h,
                'rainLastHour'  => $rainLastHour,
                'cities'        => $cityCount,
                'links'         => $linkCount,
                'routes'        => $routeCount,
            ],
            'alerts'  => $alertFeed,
            'jams'    => $jamFeed,
            'cemaden' => $cemadenFeed,
        ]);
    }
}
