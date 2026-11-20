<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PartnerRepository;
use App\Service\CollectionFreshness;
use App\Service\PartnerScopeResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CollectionHealthController extends AbstractController
{
    #[Route('/collection-health', name: 'collection_health', methods: ['GET'])]
    public function index(Request $request, PartnerScopeResolver $scope, PartnerRepository $partners, CollectionFreshness $freshness): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $partner = $scope->resolve();
        $page = max(1, min(100000, $request->query->getInt('page', 1)));
        $items = $partner === null ? $partners->findBy([], ['id' => 'ASC'], 51, ($page - 1) * 50) : [$partner];
        $hasNext = count($items) > 50;
        $items = array_slice($items, 0, 50);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = [];
        foreach ($items as $item) {
            foreach (['Waze' => $item->getLastFetchAt(), 'TVT' => $item->getLastTvtFetchAt()] as $source => $last) {
                $rows[] = ['partner' => $item->getName(), 'source' => $source]
                    + $freshness->evaluate($last, $item->getFetchIntervalSeconds(), $item->isActive(), $now);
            }
        }
        $response = $this->render('collection_health/index.html.twig', [
            'rows' => $rows, 'as_of' => $now, 'page' => $page,
            'has_next' => $hasNext, 'global_scope' => $partner === null,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
