<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeTvtRouteRepository;
use App\Repository\WazeTvtSnapshotRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/rotas', name: 'route_admin_')]
#[IsGranted('ROLE_ADMIN')]
class RouteAdminController extends AbstractController
{
    public function __construct(
        private readonly TenantContext              $tenantContext,
        private readonly WazeTvtRouteRepository     $tvtRouteRepo,
        private readonly WazeTvtSnapshotRepository  $snapshotRepo,
    ) {}

    // ─── Listagem / histórico TVT ─────────────────────────────────────────────

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner   = $this->tenantContext->requirePartner();
        $jamFilter = $request->query->get('jam');

        $routes = $this->tvtRouteRepo->findTvtByPartner(
            $partner,
            $jamFilter !== null && $jamFilter !== '' ? (int) $jamFilter : null,
        );

        $lastSnapshot = $this->snapshotRepo->findLatestByPartner($partner);

        $total  = count($routes);
        $levels = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $totalDelaySec  = 0;
        $routesWithDelay = 0;

        foreach ($routes as $r) {
            $lv = $r->getJamLevel() ?? 0;
            $levels[$lv] = ($levels[$lv] ?? 0) + 1;
            $delay = $r->getDelaySeconds();
            if ($delay !== null) {
                $totalDelaySec  += $delay;
                $routesWithDelay++;
            }
        }

        $avgDelaySec = $routesWithDelay > 0 ? (int) ($totalDelaySec / $routesWithDelay) : 0;
        $congested   = ($levels[3] ?? 0) + ($levels[4] ?? 0) + ($levels[5] ?? 0);

        return $this->render('route/index.html.twig', [
            'partner'      => $partner,
            'routes'       => $routes,
            'lastSnapshot' => $lastSnapshot,
            'kpi' => [
                'total'       => $total,
                'congested'   => $congested,
                'avgDelaySec' => $avgDelaySec,
                'levels'      => $levels,
            ],
            'jamFilter' => $jamFilter,
        ]);
    }

    // ─── Série histórica de uma rota TVT ──────────────────────────────────────

    #[Route('/historico/{wazeRouteId}', name: 'history')]
    public function history(string $wazeRouteId, Request $request): Response
    {
        $partner  = $this->tenantContext->requirePartner();
        $limit    = (int) ($request->query->get('limit', 100));
        $history  = $this->tvtRouteRepo->findHistoryByWazeId($partner, $wazeRouteId, $limit);

        if (empty($history)) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        $latest = $history[0];

        return $this->render('route/history.html.twig', [
            'partner'      => $partner,
            'latest'       => $latest,
            'history'      => $history,
            'wazeRouteId'  => $wazeRouteId,
        ]);
    }
}
