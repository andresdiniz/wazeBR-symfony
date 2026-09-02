<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\WazeTvtRouteExecution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/dashboard', name: 'dashboard')]
    public function index(Request $request): Response
    {
        /** @var Partner|null $partner */
        $partner = $this->getUser();

        if (!$partner) {
            throw $this->createAccessDeniedException();
        }

        $partnerId = $partner->getId();
        $cache = $this->container->get('cache.app');

        $routeCount = $cache->get('dashboard_routes_' . $partnerId, function () use ($partnerId): int {
            return (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(DISTINCT d.id)')
                ->from(WazeTvtRouteExecution::class, 'e')
                ->innerJoin('e.routeDefinition', 'd')
                ->where('d.partner = :partnerId')
                ->setParameter('partnerId', $partnerId)
                ->getQuery()
                ->getSingleScalarResult();
        });

        return $this->render('dashboard/index.html.twig', [
            'routeCount' => $routeCount,
        ]);
    }
}
