<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\DashboardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request, DashboardRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        $stats = $repository->getDashboardStats($partner, $filters);

        return $this->render('dashboard/index.html.twig', [
            'stats'       => $stats,
            'filters'     => $filters,
            'partner'     => $partner,
            'map_payload' => $stats['map'],
            'charts'      => $stats['charts'],
        ]);
    }

    #[Route('/dashboard/api/data', name: 'dashboard_api_data', methods: ['GET'])]
    public function apiData(Request $request, DashboardRepository $repository): Response
    {
        $stats = $this->getStats($request, $repository);
        return $this->dashboardJson($stats, $stats['filters'] ?? [], $request);
    }

    #[Route('/dashboard/api/summary', name: 'dashboard_api_summary', methods: ['GET'])]
    public function summary(Request $request, DashboardRepository $repository): Response
    {
        $stats = $this->getStats($request, $repository);

        return $this->dashboardJson([
            'totals'       => $stats['totals'] ?? [],
            'kpis'         => $stats['kpis'] ?? [],
            'health_score' => $stats['health_score'] ?? null,
            'fetch_status' => $stats['fetch_status'] ?? [],
        ], $stats['filters'] ?? [], $request);
    }

    #[Route('/dashboard/api/charts', name: 'dashboard_api_charts', methods: ['GET'])]
    public function charts(Request $request, DashboardRepository $repository): Response
    {
        $stats = $this->getStats($request, $repository);

        return $this->dashboardJson([
            'charts' => $stats['charts'] ?? [],
        ], $stats['filters'] ?? [], $request);
    }

    #[Route('/dashboard/api/map', name: 'dashboard_api_map', methods: ['GET'])]
    public function map(Request $request, DashboardRepository $repository): Response
    {
        $stats = $this->getStats($request, $repository);

        return $this->dashboardJson([
            'map' => $stats['map'] ?? [],
        ], $stats['filters'] ?? [], $request);
    }

    #[Route('/dashboard/api/recent', name: 'dashboard_api_recent', methods: ['GET'])]
    public function recent(Request $request, DashboardRepository $repository): Response
    {
        $stats = $this->getStats($request, $repository);

        return $this->dashboardJson([
            'recent' => $stats['recent'] ?? [],
        ], $stats['filters'] ?? [], $request);
    }

    #[Route('/dashboard/api/hydro', name: 'dashboard_api_hydro', methods: ['GET'])]
    public function hydro(Request $request, DashboardRepository $repository): Response
    {
        $stats = $this->getStats($request, $repository);

        return $this->dashboardJson([
            'hydro' => $stats['hydro'] ?? [],
        ], $stats['filters'] ?? [], $request);
    }

    #[Route('/dashboard/api/pluvio', name: 'dashboard_api_pluvio', methods: ['GET'])]
    public function pluvio(Request $request, DashboardRepository $repository): Response
    {
        $stats = $this->getStats($request, $repository);

        return $this->dashboardJson([
            'pluvio' => $stats['pluvio'] ?? [],
        ], $stats['filters'] ?? [], $request);
    }

    #[Route('/dashboard/api/status', name: 'dashboard_api_status', methods: ['GET'])]
    public function status(Request $request, DashboardRepository $repository): Response
    {
        $stats = $this->getStats($request, $repository);

        return $this->dashboardJson([
            'fetch_status' => $stats['fetch_status'] ?? [],
            'health_score' => $stats['health_score'] ?? null,
        ], $stats['filters'] ?? [], $request);
    }

    private function getStats(Request $request, DashboardRepository $repository): array
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        return $repository->getDashboardStats($partner, $filters);
    }

    /**
     * Resposta JSON com:
     *  - ETag (permite 304 via browser / proxy quando dados não mudaram)
     *  - X-Response-Time (observabilidade)
     *  - Cache-Control privado (não cacheia em CDN, mas permite revalidação)
     *  - X-Content-Type-Options / Referrer-Policy
     */
    private function dashboardJson(array $data, array $filters, Request $request): Response
    {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);

        $payload = [
            'ok'           => true,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'filters'      => $filters,
            'data'         => $this->normalizeDatesForJson($data),
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $response = new Response($json, 200, [
            'Content-Type'           => 'application/json; charset=UTF-8',
            'ETag'                   => '"' . md5($json) . '"',
            'Cache-Control'          => 'private, must-revalidate, max-age=0',
            'X-Response-Time'        => sprintf('%.1f', (microtime(true) - $start) * 1000),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'no-referrer',
        ]);

        // 304 automático quando o If-None-Match bate com o ETag
        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }

    private function resolvePartnerScope(): ?\App\Entity\Partner
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return null;
        }

        if ($user->isGlobalAdmin()) {
            return null;
        }

        return $user->getPartner();
    }

    /** @return array{period:string,type:string,query:string,city:?string,exclude_streets:string} */
    private function extractFilters(Request $request): array
    {
        return [
            'period'          => (string) $request->query->get('period', 'all'),
            'type'            => (string) $request->query->get('type', 'all'),
            'query'           => trim((string) $request->query->get('query', '')),
            'city'            => $request->query->get('city') ?: null,
            'exclude_streets' => trim((string) $request->query->get('exclude_streets', '')),
        ];
    }

    private function normalizeDatesForJson(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->normalizeDatesForJson($item);
            }
            return $out;
        }

        return $value;
    }
}
