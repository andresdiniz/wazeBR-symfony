<?php

namespace App\Controller;

use App\Entity\WazeRoute;
use App\Entity\WazeTvtRouteDefinition;
use App\Entity\WazeTvtRouteExecution;
use App\Repository\WazeRouteRepository;
use App\Repository\WazeTvtRouteDefinitionRepository;
use App\Repository\WazeTvtRouteExecutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/routes')]
class RouteAdminController extends AbstractController
{
    public function __construct(
        private WazeRouteRepository $routeRepo,
        private WazeTvtRouteDefinitionRepository $definitionRepo,
        private WazeTvtRouteExecutionRepository $executionRepo,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_routes_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $routes = $this->routeRepo->findAll();
        $definitions = $this->definitionRepo->findAll();

        return $this->render('admin/routes/index.html.twig', [
            'routes' => $routes,
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

        if ($route->getExternalId()) {
            $definition = $this->definitionRepo->findOneByRouteId($route->getExternalId());
            if ($definition) {
                $executions = $this->executionRepo->findByRouteId($route->getExternalId(), 50);
            }
        }

        return $this->render('admin/routes/show.html.twig', [
            'route' => $route,
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

        if (!$route->getExternalId()) {
            $this->addFlash('error', 'Route has no external ID defined.');
            return $this->redirectToRoute('admin_routes_index');
        }

        $definition = $this->definitionRepo->findOneByRouteId($route->getExternalId());
        if (!$definition) {
            $this->addFlash('warning', 'No TVT definition found for this route yet.');
        } else {
            $executions = $this->executionRepo->findByRouteId($route->getExternalId(), 1);
            if (empty($executions)) {
                $this->addFlash('info', 'Definition exists but no executions collected yet.');
            } else {
                $this->addFlash('success', sprintf('Found %d execution(s) for this route.', count($this->executionRepo->findByRouteId($route->getExternalId(), 100))));
            }
        }

        return $this->redirectToRoute('admin_routes_show', ['id' => $id]);
    }

    #[Route('/{id}/definition', name: 'admin_routes_definition', methods: ['GET'])]
    public function definition(int $id): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $route = $this->routeRepo->find($id);
        if (!$route || !$route->getExternalId()) {
            throw $this->createNotFoundException('Route not found');
        }

        $definition = $this->definitionRepo->findOneByRouteId($route->getExternalId());
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
        if (!$route || !$route->getExternalId()) {
            throw $this->createNotFoundException('Route not found');
        }

        $limit = (int) $request->query->get('limit', '50');
        $executions = $this->executionRepo->findByRouteId($route->getExternalId(), $limit);

        return $this->render('admin/routes/executions.html.twig', [
            'route' => $route,
            'executions' => $executions,
        ]);
    }
}
