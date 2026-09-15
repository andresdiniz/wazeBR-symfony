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
    public function apiData(Request $request, DashboardRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        $stats = $repository->getDashboardStats($partner, $filters);

        return $this->json([
            'ok'           => true,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'filters'      => $filters,
            'data'         => $this->normalizeDatesForJson($stats),  // ← aqui
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

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
    /**
 * Converte recursivamente \DateTimeInterface em string ISO 8601.
 *
 * Necessário porque o json_encode do PHP serializa \DateTimeImmutable
 * como objeto ({"date":"...","timezone_type":3,...}), o que quebra
 * `new Date()` no JS (Invalid Date).
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
