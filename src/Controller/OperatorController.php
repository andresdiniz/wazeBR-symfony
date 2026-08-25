<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenDataRepository;
use App\Repository\CifsEventRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeCountRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/operador', name: 'operator_')]
#[IsGranted('ROLE_USER')]
class OperatorController extends AbstractController
{
    public function __construct(
        private readonly TenantContext            $tenantContext,
        private readonly WazeAlertRepository      $alertRepo,
        private readonly WazeTrafficJamRepository $jamRepo,
        private readonly CemadenDataRepository    $cemadenRepo,
        private readonly WazeCountRepository      $wazeCountRepo,
        private readonly CifsEventRepository      $cifsRepo,
    ) {}

    #[Route('', name: 'live')]
    public function live(): Response
    {
        $partner = $this->tenantContext->requirePartner();

        return $this->render('operator/live.html.twig', [
            'partner'     => $partner,
            'partnerName' => $partner->getName(),
        ]);
    }

    /**
     * Endpoint JSON para polling ao vivo na view do operador.
     * GET /operador/api/live
     *
     * Formato consumido por templates/operator/live.html.twig (renderJams,
     * renderAlerts, renderCemaden, buildKpis): { jams[], alerts[], cemaden[] }
     * com geometria/coordenadas prontas para o Leaflet.
     */
    #[Route('/api/live', name: 'live_data', methods: ['GET'])]
    public function liveData(): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();

        // ── Congestionamentos ao vivo (últimas 3h), com geometria da via ──
        $liveJams = $this->jamRepo->findLiveByPartner($partner, 3);
        $jams = array_map(static fn ($j) => [
            'street'   => $j->getStreet(),
            'city'     => $j->getCity(),
            'level'    => $j->getLevel(),
            'speedKMH' => $j->getSpeedKmh(),
            'delay'    => $j->getDelay(),
            'length'   => $j->getLength(),
            'line'     => $j->getLine(),
            'type'     => 'JAM',
            'pubMillis'=> $j->getPubMillis(),
        ], $liveJams);

        // ── Alertas ativos (última hora) ──────────────────────────────────
        $activeAlerts = $this->alertRepo->findActiveByPartner($partner, 60);
        $alerts = array_map(static fn ($a) => [
            'uuid'       => $a->getWazeId(),
            'type'       => $a->getType(),
            'street'     => $a->getStreet(),
            'city'       => $a->getCity(),
            'lat'        => $a->getLatitude(),
            'lng'        => $a->getLongitude(),
            'confidence' => $a->getConfidence(),
            'pubMillis'  => $a->getPubMillis(),
        ], $activeAlerts);

        // ── Estações CEMADEN do parceiro ───────────────────────────────────
        $stations = $this->cemadenRepo->findByPartner($partner);
        $cemaden = array_map(static fn ($c) => [
            'name'     => $c->getStationName(),
            'lat'      => $c->getLatitude(),
            'lng'      => $c->getLongitude(),
            'rain1h'   => $c->getAccumulatedRain(),
            'critical' => in_array($c->getAlertLevel(), ['VERMELHO', 'LARANJA'], true),
        ], $stations);

        // ── KPIs auxiliares (anomalia de alertas, dados agregados) ─────────
        $alertsLast1h = $this->alertRepo->countLastHoursByPartner($partner, 1);
        $alertsLast6h = $this->alertRepo->countLastHoursByPartner($partner, 6);
        $avg6hPerHour = $alertsLast6h > 0 ? round($alertsLast6h / 6, 1) : 0;
        $liveStats    = $this->jamRepo->avgStats($partner, 3);
        $rainLastHour = $this->cemadenRepo->sumRainLastHourByPartner($partner);
        $wazeCount    = $this->wazeCountRepo->findLatest($partner);
        $cifsActive   = $this->cifsRepo->countActive($partner);

        return $this->json([
            'jams'         => $jams,
            'alerts'       => $alerts,
            'cemaden'      => $cemaden,
            'alertsLastHour' => $alertsLast1h,
            'anomaly'      => [
                'detected' => $avg6hPerHour > 0 && $alertsLast1h > ($avg6hPerHour * 2),
                'ratio'    => $avg6hPerHour > 0 ? round($alertsLast1h / $avg6hPerHour, 1) : 0,
                'avg6h'    => $avg6hPerHour,
            ],
            'liveAvgSpeed' => $liveStats['avgSpeed'],
            'liveAvgDelay' => $liveStats['avgDelay'],
            'rainLastHour' => $rainLastHour,
            'wazeJams'     => $wazeCount?->getTotalJams(),
            'wazeAlerts'   => $wazeCount?->getTotalAlerts(),
            'cifsActive'   => $cifsActive,
            'collectedAt'  => (new \DateTimeImmutable())->format('H:i:s'),
        ]);
    }
}
