<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\RouteDetailRepository;
use App\Service\PartnerScopeResolver;
use App\Service\RouteLiveNotifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Atualização ao vivo da página /routes/{id}.
 *
 *   GET /routes/live/stream    → { hub, topic }   (onde assinar o Mercure)
 *   GET /routes/{id}/live.json → leitura atual + alertas novos desde ?since=
 *
 * O Mercure só avisa que há dado novo; o conteúdo vem do live.json, que passa pela
 * autenticação e pelo escopo de parceiro (mesmo desenho do wallboard de TV).
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RouteLiveController extends AbstractController
{
    #[Route('/routes/live/stream', name: 'routes_live_stream', methods: ['GET'])]
    public function streamInfo(HubInterface $hub, PartnerScopeResolver $scope): JsonResponse
    {
        $response = $this->json([
            'hub'   => $hub->getPublicUrl(),
            'topic' => RouteLiveNotifier::topicFor($scope->resolve()),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/routes/{id}/live.json', name: 'routes_live_state', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function state(
        int $id,
        Request $request,
        RouteDetailRepository $repository,
        PartnerScopeResolver $scope,
    ): JsonResponse {
        $since = (string) $request->query->get('since', '');

        $state = $repository->getLiveState(
            $scope->resolve(),
            $id,
            $since !== '' && strtotime($since) !== false ? $since : null,
        ) ?? throw $this->createNotFoundException('Rota não encontrada.');

        $response = $this->json(['ok' => true] + $state);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
