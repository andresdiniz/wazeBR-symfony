<?php

namespace App\Controller;

use App\Entity\WazeTvtRouteHistory;
use App\Repository\WazeRouteRepository;
use App\Repository\WazeTvtRouteDefinitionRepository;
use App\Repository\WazeTvtRouteHistoryRepository;
use App\Repository\WazeTvtRouteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/routes')]
class RouteAdminController extends AbstractController
{
    public function __construct(
        private readonly WazeRouteRepository $routeRepo,
        private readonly WazeTvtRouteRepository $tvtRouteRepo,
        private readonly WazeTvtRouteDefinitionRepository $definitionRepo,
        private readonly WazeTvtRouteHistoryRepository $historyRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_routes_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $routes      = $this->routeRepo->findAll();
        $definitions = $this->definitionRepo->findAll();

        return $this->render('admin/routes/index.html.twig', [
            'routes'      => $routes,
            'definitions' => $definitions,
        ]);
    }

    #[Route('/{id}', name: 'admin_routes_show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $route = $this->routeRepo->find($id);
        if (!$route) {
            throw $this->createNotFoundException('Route not found');
        }

        $definition = null;
        $executions = [];

        if ($route->getWazeId()) {
            $tvtRoute = $this->tvtRouteRepo->findOneByExternalRouteId($route->getWazeId());
            if ($tvtRoute) {
                $definition = $tvtRoute->getCurrentDefinition();
                $executions = $this->historyRepo->findRecentByRoute($tvtRoute->getId(), 50);
            }
        }

        return $this->render('admin/routes/show.html.twig', [
            'route'      => $route,
            'definition' => $definition,
            'executions' => $executions,
        ]);
    }

    #[Route('/{id}/sync', name: 'admin_routes_sync', methods: ['POST'])]
    public function sync(Request $request, int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $route = $this->routeRepo->find($id);
        if (!$route) {
            throw $this->createNotFoundException('Route not found');
        }

        if (!$route->getWazeId()) {
            $this->addFlash('error', 'Route has no external ID defined.');
            return $this->redirectToRoute('admin_routes_index');
        }

        $tvtRoute = $this->tvtRouteRepo->findOneByExternalRouteId($route->getWazeId());

        if (!$tvtRoute || !$tvtRoute->getCurrentDefinition()) {
            $this->addFlash('warning', 'No TVT definition found for this route yet.');
        } else {
            $count = count($this->historyRepo->findRecentByRoute($tvtRoute->getId(), 100));
            if ($count === 0) {
                $this->addFlash('info', 'Definition exists but no executions collected yet.');
            } else {
                $this->addFlash('success', sprintf('Found %d execution(s) for this route.', $count));
            }
        }

        return $this->redirectToRoute('admin_routes_show', ['id' => $id]);
    }

    #[Route('/{id}/definition', name: 'admin_routes_definition', methods: ['GET'])]
    public function definition(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $route = $this->routeRepo->find($id);
        if (!$route || !$route->getWazeId()) {
            throw $this->createNotFoundException('Route not found');
        }

        $tvtRoute = $this->tvtRouteRepo->findOneByExternalRouteId($route->getWazeId());
        $definition = $tvtRoute?->getCurrentDefinition();

        if (!$definition) {
            throw $this->createNotFoundException('TVT definition not found');
        }

        return $this->render('admin/routes/definition.html.twig', [
            'definition' => $definition,
        ]);
    }

    #[Route('/{id}/executions', name: 'admin_routes_executions', methods: ['GET'])]
    public function executions(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $route = $this->routeRepo->find($id);
        if (!$route || !$route->getWazeId()) {
            throw $this->createNotFoundException('Route not found');
        }

        $limit    = max(1, (int) $request->query->get('limit', '50'));
        $tvtRoute = $this->tvtRouteRepo->findOneByExternalRouteId($route->getWazeId());
        $executions = $tvtRoute
            ? $this->historyRepo->findRecentByRoute($tvtRoute->getId(), $limit)
            : [];

        return $this->render('admin/routes/executions.html.twig', [
            'route'      => $route,
            'executions' => $executions,
        ]);
    }
}
