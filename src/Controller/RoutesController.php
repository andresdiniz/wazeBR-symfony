<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use App\Entity\Partner;
use App\Entity\User;
use App\Repository\RoutesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\RouteDetailRepository;
use App\Repository\RouteCompareRepository;

#[Route('/routes', name: 'routes_')]
final class RoutesController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, RoutesRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $payload = $repository->getRoutesDashboard($partner, $filters);

        return $this->render('routes/index.html.twig', [
            'routes'   => $payload['routes'],
            'stats'    => $payload['stats'],
            'map'      => $payload['map'],
            'filters'  => $filters,
            'partner'  => $partner,
        ]);
    }

    #[Route('/api/data', name: 'api_data', methods: ['GET'])]
    public function apiData(Request $request, RoutesRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $payload = $repository->getRoutesDashboard($partner, $filters);

        return $this->json([
            'ok'           => true,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'filters'      => $filters,
            'data'         => $this->normalizeDatesForJson($payload),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, RouteDetailRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $detail  = $repository->getRouteDetail($partner, $id);

        if ($detail === null) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        return $this->render('routes/show.html.twig', [
            'route'             => $detail['route'],
            'current'           => $detail['current'],
            'stats'             => $detail['stats'],
            'heatmap'           => $detail['heatmap'],
            'timeline'          => $detail['timeline'],
            'byHour'            => $detail['byHour'],
            'byDow'             => $detail['byDow'],
            'jamDistribution'   => $detail['jamDistribution'],
            'topSubRoutes'      => $detail['topSubRoutes'],
            'irregularities'    => $detail['irregularities'],
            'nearbyAlerts'      => $detail['nearbyAlerts']     ?? [],
            'routePolyline'     => $detail['routePolyline']    ?? [],
            'subRoutesPolyline' => $detail['subRoutesPolyline']?? [],
            'partner'           => $partner,
        ]);
    }

    #[Route('/compare', name: 'compare', methods: ['GET'])]
    public function compare(
        Request $request,
        RouteCompareRepository $repository,
        Connection $connection,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $aId = (int) $request->query->get('a', 0);
        $bId = (int) $request->query->get('b', 0);

        $allRoutes = $this->getRoutesList($connection, $partner);

        if ($aId <= 0 || $bId <= 0 || $aId === $bId) {
            return $this->render('routes/compare.html.twig', [
                'routes'    => $allRoutes,
                'compare'   => null,
                'aId'       => $aId,
                'bId'       => $bId,
                'partner'   => $partner,
            ]);
        }

        $compare = $repository->compare($partner, $aId, $bId);
        if ($compare === null) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        return $this->render('routes/compare.html.twig', [
            'routes'  => $allRoutes,
            'compare' => $compare,
            'aId'     => $aId,
            'bId'     => $bId,
            'partner' => $partner,
        ]);
    }

    /** @return list<array{id:int,name:string,from:?string,to:?string}> */
    private function getRoutesList(Connection $connection, ?Partner $partner): array
    {
        $params = [];
        $pf = '';
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $rows = $connection->executeQuery(
            "SELECT id, name, from_name, to_name
             FROM waze_tvt_route
             WHERE is_active = 1 {$pf}
             ORDER BY name ASC",
            $params
        )->fetchAllAssociative();

        return array_map(static fn ($r) => [
            'id'   => (int) $r['id'],
            'name' => (string) ($r['name'] ?: 'Rota #'.$r['id']),
            'from' => $r['from_name'],
            'to'   => $r['to_name'],
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function resolvePartnerScope(): ?Partner
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) return null;
        if ($user->isGlobalAdmin()) return null;
        return $user->getPartner();
    }

    /** @return array{query:string,level:string} */
    private function extractFilters(Request $request): array
    {
        return [
            'query' => trim((string) $request->query->get('q', '')),
            'level' => (string) $request->query->get('level', 'all'),
        ];
    }

    /**
     * Serializa \DateTimeInterface recursivamente pra ISO 8601,
     * evitando o "Invalid Date" no JS.
     */
    private function normalizeDatesForJson(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalizeDatesForJson($v);
            }
            return $out;
        }
        return $value;
    }
}
