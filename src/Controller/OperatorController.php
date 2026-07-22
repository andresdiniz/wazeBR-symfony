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
     */
    #[Route('/api/live', name: 'live_data', methods: ['GET'])]
    public function liveData(): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();

        $alertsLast1h = $this->alertRepo->countLastHoursByPartner($partner, 1);
        $alertsLast6h = $this->alertRepo->countLastHoursByPartner($partner, 6);
        $avg6hPerHour = $alertsLast6h > 0 ? round($alertsLast6h / 6, 1) : 0;
        $liveJams     = $this->jamRepo->findLiveByPartner($partner, 3);
        $liveStats    = $this->jamRepo->avgStats($partner, 3);
        $rainLastHour = $this->cemadenRepo->sumRainLastHourByPartner($partner);
        $wazeCount    = $this->wazeCountRepo->findLatest($partner);
        $cifsActive   = $this->cifsRepo->countActive($partner);

        return $this->json([
            'alerts1h'     => $alertsLast1h,
            'anomaly'      => [
                'detected' => $avg6hPerHour > 0 && $alertsLast1h > ($avg6hPerHour * 2),
                'ratio'    => $avg6hPerHour > 0 ? round($alertsLast1h / $avg6hPerHour, 1) : 0,
                'avg6h'    => $avg6hPerHour,
            ],
            'liveJams'     => count($liveJams),
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
