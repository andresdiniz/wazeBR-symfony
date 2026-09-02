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

        $partnerId = $user->getPartner()?->getId();
        if (!$partnerId) {
            throw $this->createAccessDeniedException('UsuÃ¡rio sem parceiro associado.');
        }

        // Query corrigida: WazeTvtRouteExecution nÃ£o tem campo wazeRouteId
        // Contamos execucoes distintas por id diretamente
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(DISTINCT r.id)')
            ->from(WazeTvtRouteExecution::class, 'r')
            ->where('r.partner = :partnerId')
            ->andWhere('r.isSubRoute = :false')
            ->setParameter('partnerId', $partnerId)
            ->setParameter('false', false, 'boolean');

        $count = $qb->getQuery()->getSingleScalarResult();

        return $this->render('dashboard/index.html.twig', [
            'count' => $count,
        ]);
    }
}
