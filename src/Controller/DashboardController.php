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

    /**
     * Endpoint JSON para refresh parcial sem recarregar a página.
     */
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
            'data'         => $stats,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function resolvePartnerScope(): ?\App\Entity\Partner
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        // ROLE_ADMIN global (sem partner) → enxerga tudo
        if ($user->isGlobalAdmin()) {
            return null;
        }

        return $user->getPartner();
    }

    /** @return array{period:string,type:string,query:string,city:?string} */
    private function extractFilters(Request $request): array
    {
        return [
            'period' => (string) $request->query->get('period', 'all'),
            'type'   => (string) $request->query->get('type', 'all'),
            'query'  => trim((string) $request->query->get('query', '')),
            'city'   => $request->query->get('city') ?: null,
        ];
    }
}
