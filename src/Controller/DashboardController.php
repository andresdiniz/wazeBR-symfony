<?php

namespace App\Controller;

use App\Entity\WazeTvtRouteExecution;
use App\Repository\WazeTvtRouteExecutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WazeTvtRouteExecutionRepository $routeExecutionRepository
    ) {
    }

    #[Route('/dashboard', name: 'dashboard_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // WazeTvtRouteExecution não tem campos partner ou wazeRouteId
        // Contamos todas as execucoes (filtro por parceiro deve ser feito via JOIN ou outro metodo)
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(DISTINCT r.id)')
            ->from(WazeTvtRouteExecution::class, 'r')
            ->where('r.isSubRoute = :false')
            ->setParameter('false', false, 'boolean');

        $count = $qb->getQuery()->getSingleScalarResult();

        return $this->render('dashboard/index.html.twig', [
            'count' => $count,
        ]);
    }
}
