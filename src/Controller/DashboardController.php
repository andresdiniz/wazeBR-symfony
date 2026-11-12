<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\DashboardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardController extends AbstractController
{
    // Até 90s aqui + até 30s no repositório: idade máxima aproximada de 120s.
    private const CACHE_TTL = 90;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request, DashboardRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $partner = $this->resolvePartnerScope();
        $stats = $this->loadStats($repository, $partner, $this->extractFilters($request));
        $response = $this->render('dashboard/index.html.twig', [
            'stats' => $stats, 'filters' => $stats['filters'], 'partner' => $partner,
            'map_payload' => $stats['map'], 'charts' => $stats['charts'],
        ]);
        $response->headers->set('Cache-Control', 'private, no-cache');
        return $response;
    }

    #[Route('/dashboard/api/data', name: 'dashboard_api_data', defaults: ['section' => 'data'], methods: ['GET'])]
    #[Route('/dashboard/api/summary', name: 'dashboard_api_summary', defaults: ['section' => 'summary'], methods: ['GET'])]
    #[Route('/dashboard/api/charts', name: 'dashboard_api_charts', defaults: ['section' => 'charts'], methods: ['GET'])]
    #[Route('/dashboard/api/map', name: 'dashboard_api_map', defaults: ['section' => 'map'], methods: ['GET'])]
    #[Route('/dashboard/api/recent', name: 'dashboard_api_recent', defaults: ['section' => 'recent'], methods: ['GET'])]
    #[Route('/dashboard/api/hydro', name: 'dashboard_api_hydro', defaults: ['section' => 'hydro'], methods: ['GET'])]
    #[Route('/dashboard/api/pluvio', name: 'dashboard_api_pluvio', defaults: ['section' => 'pluvio'], methods: ['GET'])]
    #[Route('/dashboard/api/status', name: 'dashboard_api_status', defaults: ['section' => 'status'], methods: ['GET'])]
    public function api(Request $request, DashboardRepository $repository, string $section = 'data'): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $stats = $this->loadStats($repository, $this->resolvePartnerScope(), $this->extractFilters($request));
        $keys = match ($section) {
            'summary' => ['totals', 'kpis', 'health_score', 'fetch_status'],
            'status' => ['fetch_status', 'health_score'],
            'charts', 'map', 'recent', 'hydro', 'pluvio' => [$section],
            default => array_keys($stats),
        };
        $payload = [
            'ok' => true, 'locale' => $request->getLocale(),
            'generated_at' => $stats['updated_at'] ?? null,
            'filters' => $stats['filters'],
            'data' => array_intersect_key($stats, array_flip($keys)),
        ];
        $json = json_encode($this->normalizeDatesForJson($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $response = new Response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Language' => str_replace('_', '-', $request->getLocale()),
            'Vary' => 'Cookie, Accept-Language',
            'Cache-Control' => 'private, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Response-Time' => sprintf('%.1f', (microtime(true) - $request->server->get('REQUEST_TIME_FLOAT', microtime(true))) * 1000),
        ]);
        $response->setEtag(hash('sha256', $json));
        $response->isNotModified($request);
        return $response;
    }

    private function loadStats(DashboardRepository $repository, ?Partner $partner, array $filters): array
    {
        // Não cria uma chave persistente para cada texto ou regex digitados.
        if ($filters['query'] !== '' || $filters['exclude_streets'] !== '') {
            return $repository->getDashboardStats($partner, $filters);
        }
        $key = 'dashboard_v4_' . ($partner?->getId() ?? 'global') . '_' . hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
        return $this->cache->get($key, function (ItemInterface $item) use ($repository, $partner, $filters): array {
            $item->expiresAfter(self::CACHE_TTL);
            return $repository->getDashboardStats($partner, $filters);
        });
    }

    private function resolvePartnerScope(): ?Partner
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($user->isGlobalAdmin()) {
            return null;
        }
        $partner = $user->getPartner();
        if ($partner === null) {
            throw $this->createAccessDeniedException('Usuário sem parceiro associado.');
        }
        return $partner;
    }

    private function extractFilters(Request $request): array
    {
        $period = (string) $request->query->get('period', 'all');
        $type = (string) $request->query->get('type', 'all');
        $city = mb_substr(trim((string) $request->query->get('city', '')), 0, 200);
        return [
            'period' => in_array($period, ['all', 'today', 'week', 'month', 'year'], true) ? $period : 'all',
            'type' => in_array($type, ['all', 'alerts', 'jams', 'weather', 'routes', 'hydro', 'pluvio'], true) ? $type : 'all',
            'query' => mb_substr(trim((string) $request->query->get('query', '')), 0, 200),
            'city' => $city !== '' ? $city : null,
            'exclude_streets' => mb_substr(trim((string) $request->query->get('exclude_streets', '')), 0, 6000),
        ];
    }

    private function normalizeDatesForJson(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeDatesForJson($item);
            }
        }
        return $value;
    }
}
