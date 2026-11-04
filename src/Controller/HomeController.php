<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\DashboardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(DashboardRepository $dashboardRepository): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            $response = $this->render('landing.html.twig');
            $response->setPublic();
            $response->setMaxAge(3660);
            $response->setSharedMaxAge(6000);
            $response->headers->addCacheControlDirective('must-revalidate');

            return $response;
        }

        $partner = $user->isGlobalAdmin() ? null : $user->getPartner();

        $stats = $dashboardRepository->getDashboardStats($partner, [
            'period' => 'today',
            'type' => 'all',
            'query' => '',
            'city' => null,
            'exclude_streets' => '',
        ]);

        return $this->render('home/command_center.html.twig', [
            'user' => $user,
            'partner' => $partner,
            'stats' => $stats,
            'totals' => $stats['totals'] ?? [],
            'kpis' => $stats['kpis'] ?? [],
            'health_score' => $stats['health_score'] ?? null,
            'map_payload' => $stats['map'] ?? [],
            'charts' => $stats['charts'] ?? [],
            'recent' => $stats['recent'] ?? [],
            'hydro' => $stats['hydro'] ?? [],
            'pluvio' => $stats['pluvio'] ?? [],
            'fetch_status' => $this->normalizeFetchStatus($stats['fetch_status'] ?? []),
        ]);
    }

    /**
     * Converte valores de status para representação segura em Twig.
     *
     * @param array<string, mixed> $fetchStatus
     *
     * @return array<string, string>
     */
    private function normalizeFetchStatus(array $fetchStatus): array
    {
        $normalized = [];

        foreach ($fetchStatus as $source => $status) {
            $normalized[(string) $source] = $this->stringifyStatus($status);
        }

        return $normalized;
    }

    private function stringifyStatus(mixed $status): string
    {
        if ($status instanceof \DateTimeInterface) {
            return $status->format('d/m/Y H:i');
        }

        if (is_array($status)) {
            $parts = [];

            foreach ($status as $value) {
                $text = $this->stringifyStatus($value);

                if ('' !== $text) {
                    $parts[] = $text;
                }
            }

            return implode(' • ', $parts);
        }

        if (null === $status) {
            return '—';
        }

        if (is_bool($status)) {
            return $status ? 'OK' : 'Falha';
        }

        if (is_scalar($status)) {
            return (string) $status;
        }

        return '';
    }
}
