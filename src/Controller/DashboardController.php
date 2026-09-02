<?php

namespace App\Controller;

use App\Entity\WazeTvtRouteExecution;
use App\Repository\WazeTvtRouteExecutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Cache\Adapter\AdapterInterface;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdapterInterface $cache,
        private readonly WazeTvtRouteExecutionRepository $routeExecutionRepository
    ) {
    }

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

        $emptyRouteId = '00000000-0000-0000-0000-000000000000';

        // Query corrigida: sem JOIN por snapshot (WazeTvtRouteExecution nÃ£o tem associaÃ§Ã£o "snapshot")
        // Filtramos diretamente por r.partner = :partnerId
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(DISTINCT r.wazeRouteId)')
            ->from(WazeTvtRouteExecution::class, 'r')
            ->where('r.partner = :partnerId')
            ->andWhere('r.isSubRoute = :false')
            ->andWhere('r.wazeRouteId IS NOT NULL')
            ->andWhere('r.wazeRouteId != :emptyRouteId')
            ->setParameter('partnerId', $partnerId)
            ->setParameter('false', false, 'boolean')
            ->setParameter('emptyRouteId', $emptyRouteId);

        $count = $qb->getQuery()->getSingleScalarResult();

        return $this->render('dashboard/index.html.twig', [
            'count' => $count,
        ]);
    }
}
