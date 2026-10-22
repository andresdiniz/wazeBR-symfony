<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\RouteFavoriteRepository;
use App\Service\PartnerScopeResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RouteFavoriteController extends AbstractController
{
    /**
     * Alterna o favorito da rota para o usuário logado.
     *
     * Dois modos, mesmo endpoint:
     *   - JavaScript (fetch, Accept: application/json) → responde JSON {ok, favorited};
     *   - formulário HTML comum (sem JavaScript)       → redireciona de volta para a rota.
     *
     * Token CSRF `route_favorite_{id}`: campo `_token` do formulário ou cabeçalho X-CSRF-Token.
     */
    #[Route('/routes/{id}/favorite', name: 'routes_favorite_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(
        int $id,
        Request $request,
        RouteFavoriteRepository $favorites,
        PartnerScopeResolver $scope,
    ): Response {
        $wantsJson = $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        $token = (string) ($request->headers->get('X-CSRF-Token') ?: $request->request->get('_token', ''));
        if (!$this->isCsrfTokenValid('route_favorite_' . $id, $token)) {
            if ($wantsJson) {
                return $this->json(['ok' => false, 'error' => 'csrf'], Response::HTTP_FORBIDDEN);
            }

            // Sessão expirada: volta para a rota (o token da página nova será válido).
            return $this->redirectToRoute('routes_show', ['id' => $id]);
        }

        $partner = $scope->resolve();
        if (!$favorites->routeVisible($id, $partner)) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        $state = $favorites->toggle((int) $scope->user()->getId(), $id);

        if (!$wantsJson) {
            return $this->redirectToRoute('routes_show', ['id' => $id]);
        }

        $response = $this->json(['ok' => true, 'favorited' => $state]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
