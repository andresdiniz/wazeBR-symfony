<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\AnalyticsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AnalyticsController extends AbstractController
{
    private const CACHE_TTL = 120;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    #[Route('/analytics', name: 'analytics', methods: ['GET'])]
    public function index(Request $request, AnalyticsRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $partner = $this->resolvePartnerScope();
        $filters = $this->extractFilters($request);

        $cacheKey = sprintf(
            'analytics_v1_%s_%s',
            $partner?->getId() ?? 'all',
            hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR))
        );

        $stats = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($repository, $partner, $filters): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $repository->getAnalytics($partner, $filters);
            }
        );

        $response = $this->render('analytics/index.html.twig', [
            'stats' => $stats,
            'filters' => $filters,
            'partner' => $partner,
        ]);

        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
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

    /**
     * @return array{days:int,city:string|null}
     */
    private function extractFilters(Request $request): array
    {
        $days = (int) $request->query->get('days', 30);
        $city = trim((string) $request->query->get('city', ''));

        return [
            'days' => in_array($days, [7, 30, 90], true) ? $days : 30,
            'city' => $city !== '' ? mb_substr($city, 0, 200) : null,
        ];
    }
}
