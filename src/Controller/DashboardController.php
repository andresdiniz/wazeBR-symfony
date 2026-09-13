<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DashboardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(
        DashboardRepository $dashboardRepository,
        CacheInterface $cache,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $partner = method_exists($user, 'getPartner') ? $user->getPartner() : null;
        $partnerId = $partner?->getId();

        // Cache por partner (ou global para super admins sem partner)
        $cacheKey = sprintf('dashboard_stats_%s', $partnerId ?? 'global');

        try {
            $stats = $cache->get($cacheKey, function (ItemInterface $item) use ($dashboardRepository, $partner): array {
                // TTL de 2 minutos — dados de trânsito mudam rápido
                $item->expiresAfter(120);
                return $dashboardRepository->getDashboardStats($partner);
            });
        } catch (\Throwable) {
            // Se o cache falhar, busca direto sem travar a página
            try {
                $stats = $dashboardRepository->getDashboardStats($partner);
            } catch (\Throwable) {
                $stats = $this->emptyStats();
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'stats'   => $stats,
            'partner' => $partner,
        ]);
    }

    /** Retorna estrutura vazia para exibir dashboard sem erro quando o banco falha. */
    private function emptyStats(): array
    {
        return [
            'total_alerts'   => 0,
            'total_jams'     => 0,
            'total_weather'  => 0,
            'total_partners' => 0,
            'total_users'    => 0,
            'recent_alerts'  => [],
            'recent_jams'    => [],
            'recent_weather' => [],
            'recent_routes'  => [],
            'alerts_by_type' => [],
            'jam_levels'     => [],
            'top_cities'     => [],
            'health_score'   => 0,
            'updated_at'     => new \DateTimeImmutable(),
        ];
    }
}
