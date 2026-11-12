<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Repository\RouteCompareRepository;
use App\Repository\RouteDetailRepository;
use App\Repository\RouteFavoriteRepository;
use App\Repository\RoutesRepository;
use App\Service\PartnerScopeResolver;
use App\Service\RouteCsvExporter;
use App\Service\RouteOverviewPresenter;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/routes', name: 'routes_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RoutesController extends AbstractController
{
    public function __construct(
        private readonly PartnerScopeResolver $scope,
        private readonly RouteOverviewPresenter $overviewPresenter,
        #[Autowire(service: 'routes.structure.cache')]
        private readonly CacheInterface $routesStructureCache,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, RoutesRepository $repository): Response
    {
        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $payload = $this->loadDashboardPayload($repository, $partner, $filters);
        $overview = $this->overviewPresenter->present($payload);

        $response = $this->render('routes/index.html.twig', [
            'routes' => $payload['routes'],
            'stats' => $payload['stats'],
            'map' => $payload['map'],
            'overview' => $overview,
            'filters' => $filters,
            'partner' => $partner,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/api/data', name: 'api_data', methods: ['GET'])]
    public function apiData(Request $request, RoutesRepository $repository): JsonResponse
    {
        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);
        $payload = $this->loadDashboardPayload($repository, $partner, $filters);

        $response = $this->json([
            'ok' => true,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'filters' => $filters,
            'data' => $this->normalizeDatesForJson($payload),
            'overview' => $this->normalizeDatesForJson($this->overviewPresenter->present($payload)),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id, RouteDetailRepository $repository, RouteFavoriteRepository $favorites): Response
    {
        $partner = $this->resolvePartnerScope();
        $detail = $repository->getRouteDetail($partner, $id) ?? throw $this->createNotFoundException('Rota não encontrada.');
        $userId = (int) $this->scope->user()->getId();
        return $this->render('routes/show.html.twig', $detail + ['partner' => $partner, 'isFavorite' => $favorites->isFavorite($userId, $id)]);
    }

    #[Route('/{id}/export.csv', name: 'export_csv', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function exportCsv(int $id, Request $request, RouteDetailRepository $repository, RouteCsvExporter $exporter): StreamedResponse
    {
        $type = (string) $request->query->get('type', 'timeline');
        if (!RouteCsvExporter::isValidType($type)) {
            throw new BadRequestHttpException('Tipo de exportação inválido.');
        }
        $partner = $this->resolvePartnerScope();
        $detail = $repository->getRouteDetail($partner, $id) ?? throw $this->createNotFoundException('Rota não encontrada.');
        $response = new StreamedResponse(function () use ($exporter, $type, $detail): void {
            $out = fopen('php://output', 'w');
            $exporter->write($out, $type, $detail);
            fclose($out);
        });
        return $this->csvHeaders($response, $exporter->filename((string) $detail['route']['name'], $id, $type));
    }

    #[Route('/{id}/export/historico.csv', name: 'export_historico_csv', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function exportHistoricoCsv(int $id, Request $request, RouteDetailRepository $repository, RouteCsvExporter $exporter): StreamedResponse
    {
        $days = $this->validateDays($request->query->get('days', '30'));
        $sep = $this->validateSep($request->query->get('sep', ';'));
        $historico = $repository->getHistorico($this->resolvePartnerScope(), $id, $days) ?? throw $this->createNotFoundException('Rota não encontrada.');
        $response = new StreamedResponse(function () use ($exporter, $historico, $sep): void {
            $out = fopen('php://output', 'w');
            $exporter->writeHistorico($out, $historico, $sep);
            fclose($out);
        });
        return $this->csvHeaders($response, $exporter->filename((string) $historico['route']['name'], $id, "historico_{$days}d"));
    }

    private function csvHeaders(StreamedResponse $response, string $filename): StreamedResponse
    {
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    #[Route('/compare', name: 'compare', methods: ['GET'])]
    public function compare(Request $request, RouteCompareRepository $repository, Connection $connection): Response
    {
        $partner = $this->resolvePartnerScope();
        $aId = $this->validateRouteId($request->query->get('a', '0'));
        $bId = $this->validateRouteId($request->query->get('b', '0'));
        if ($aId <= 0 || $bId <= 0 || $aId === $bId) {
            return $this->render('routes/compare.html.twig', ['routes' => $this->getRoutesList($connection, $partner), 'compare' => null, 'aId' => $aId, 'bId' => $bId, 'partner' => $partner]);
        }
        $allRoutes = $this->getRoutesList($connection, $partner);
        $compare = $repository->compare($partner, $aId, $bId);
        if ($compare === null) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }
        return $this->render('routes/compare.html.twig', ['routes' => $allRoutes, 'compare' => $compare, 'aId' => $aId, 'bId' => $bId, 'partner' => $partner]);
    }

    private function getRoutesList(Connection $connection, ?Partner $partner): array
    {
        $params = [];
        $pf = '';
        if ($partner !== null) {
            $pf = ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }
        $rows = $connection->executeQuery("SELECT id, name, from_name, to_name FROM waze_tvt_route WHERE is_active = 1 {$pf} ORDER BY name ASC", $params)->fetchAllAssociative();
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) ($r['name'] ?: 'Rota #'.$r['id']), 'from' => $r['from_name'], 'to' => $r['to_name']], $rows);
    }

    private function loadDashboardPayload(RoutesRepository $repository, ?Partner $partner, array $filters): array
    {
        $scopeKey = $partner !== null ? 'partner_' . $partner->getId() : 'authorized_global';
        $entry = $this->routesStructureCache->get('routes_structure_v1_' . $scopeKey, function (ItemInterface $item) use ($repository, $partner): array {
            $item->expiresAfter(600);
            return ['loadedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'data' => $repository->getRoutesStructure($partner)];
        }, 0.0);
        $metrics = $repository->getLatestMetrics($partner);
        return $repository->assembleDashboard($entry['data'], $metrics, $filters) + ['structureLoadedAt' => $entry['loadedAt'], 'metricsLoadedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
    }

    private function resolvePartnerScope(): ?Partner
    {
        return $this->scope->resolve();
    }

    private function extractFilters(Request $request): array
    {
        return ['query' => trim((string) $request->query->get('q', '')), 'level' => (string) $request->query->get('level', 'all')];
    }

    private function normalizeDatesForJson(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) { return $value->format(DATE_ATOM); }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) { $out[$k] = $this->normalizeDatesForJson($v); }
            return $out;
        }
        return $value;
    }

    private function validateDays(mixed $value): int
    {
        $days = (int) $value;
        if ($days < 1 || $days > 365) { throw new BadRequestHttpException('Parâmetro days deve estar entre 1 e 365.'); }
        return $days;
    }

    private function validateSep(mixed $value): string
    {
        $sep = (string) $value;
        if ($sep !== ',' && $sep !== ';') { throw new BadRequestHttpException('Parâmetro sep deve ser "," ou ";".'); }
        return $sep;
    }

    private function validateRouteId(mixed $value): int
    {
        $id = (int) $value;
        if ($id < 0) { throw new BadRequestHttpException('IDs de rota devem ser inteiros não negativos.'); }
        return $id;
    }
}
