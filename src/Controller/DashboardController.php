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

        $partner = $user->getPartner();
        $partnerLabel = $partner ? ($partner->getName() ?? 'Parceiro') : 'Sem parceiro';

        // Query minimalista: conta todas as execucoes sem filtros
        // (WazeTvtRouteExecution não tem campos partner, wazeRouteId ou isSubRoute)
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(r.id)')
            ->from(WazeTvtRouteExecution::class, 'r');

        $count = $qb->getQuery()->getSingleScalarResult();

        return $this->render('dashboard/index.html.twig', [
            'count' => $count,
            'partnerLabel' => $partnerLabel,
        ]);
    }
}
