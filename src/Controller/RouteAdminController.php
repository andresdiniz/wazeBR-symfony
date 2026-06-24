<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WazeRoute;
use App\Entity\WazeRouteLink;
use App\Repository\WazeRouteLinkRepository;
use App\Repository\WazeRouteRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/rotas', name: 'route_admin_')]
#[IsGranted('ROLE_ADMIN')]
class RouteAdminController extends AbstractController
{
    public function __construct(
        private readonly TenantContext           $tenantContext,
        private readonly WazeRouteRepository     $routeRepo,
        private readonly WazeRouteLinkRepository $linkRepo,
    ) {}

    // ─── Rotas ────────────────────────────────────────────────────────────────

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $partner = $this->tenantContext->requirePartner();

        return $this->render('admin/route/index.html.twig', [
            'partner' => $partner,
            'routes'  => $this->routeRepo->findByPartner($partner, activeOnly: false),
        ]);
    }

    #[Route('/nova', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();

        if ($request->isMethod('POST')) {
            $route = (new WazeRoute())
                ->setPartner($partner)
                ->setName((string) $request->request->get('name'))
                ->setDescription($request->request->get('description'))
                ->setCoordinates(json_decode((string) $request->request->get('coordinates', '[]'), true) ?? []);

            $this->routeRepo->save($route);
            $this->addFlash('success', "Rota '{$route->getName()}' criada.");

            return $this->redirectToRoute('route_admin_show', ['id' => $route->getId()]);
        }

        return $this->render('admin/route/new.html.twig', ['partner' => $partner]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $route   = $this->routeRepo->findOneByPartner($id, $partner);

        if (!$route) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }

        return $this->render('admin/route/show.html.twig', [
            'partner' => $partner,
            'route'   => $route,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(int $id): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $route   = $this->routeRepo->findOneByPartner($id, $partner);

        if (!$route) {
            throw $this->createNotFoundException();
        }

        $route->setIsActive(!$route->isActive());
        $this->routeRepo->save($route);
        $this->addFlash('success', "Rota '{$route->getName()}' " . ($route->isActive() ? 'ativada' : 'desativada') . '.');

        return $this->redirectToRoute('route_admin_index');
    }

    // ─── Sub-rotas (links) ────────────────────────────────────────────────────

    /** Renomeado de addLink() para createLink() — evita conflito com AbstractController::addLink() */
    #[Route('/{routeId}/links', name: 'link_new', methods: ['POST'])]
    public function createLink(int $routeId, Request $request): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();
        $route   = $this->routeRepo->findOneByPartner($routeId, $partner);

        if (!$route) {
            return $this->json(['error' => 'Rota não encontrada.'], 404);
        }

        $link = (new WazeRouteLink())
            ->setRoute($route)
            ->setName((string) $request->request->get('name'))
            ->setCoordinates(json_decode((string) $request->request->get('coordinates', '[]'), true) ?? [])
            ->setSortOrder((int) $request->request->get('sort_order', 0));

        $this->linkRepo->save($link);

        return $this->json([
            'id'        => $link->getId(),
            'name'      => $link->getName(),
            'sortOrder' => $link->getSortOrder(),
        ], 201);
    }

    #[Route('/{routeId}/links/{linkId}', name: 'link_delete', methods: ['DELETE'])]
    public function deleteLink(int $routeId, int $linkId): JsonResponse
    {
        $partner = $this->tenantContext->requirePartner();
        $route   = $this->routeRepo->findOneByPartner($routeId, $partner);

        if (!$route) {
            return $this->json(['error' => 'Rota não encontrada.'], 404);
        }

        $link = $this->linkRepo->find($linkId);

        if (!$link || $link->getRoute()->getId() !== $route->getId()) {
            return $this->json(['error' => 'Sub-rota não encontrada.'], 404);
        }

        $em = $this->routeRepo->getEntityManager();
        $em->remove($link);
        $em->flush();

        return $this->json(['deleted' => true]);
    }
}
