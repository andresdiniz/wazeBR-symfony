<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeRoute>
 */
class WazeRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, WazeRoute::class);
    }

    /**
     * @return WazeRoute[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('wr')
            ->where('wr.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('wr.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByLink(string $link): ?WazeRoute
    {
        return $this->createQueryBuilder('wr')
            ->where('wr.isActive = :isActive')
            ->andWhere('wr.link = :link')
            ->setParameter('isActive', true)
            ->setParameter('link', $link)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAllActive(): array
    {
        return $this->findActive();
    }

    public function findActiveByPartnerSlug(string $slug): array
    {
        return $this->createQueryBuilder('wr')
            ->innerJoin('wr.partner', 'p')
            ->where('wr.isActive = :isActive')
            ->andWhere('p.slug = :slug')
            ->setParameter('isActive', true)
            ->setParameter('slug', $slug)
            ->orderBy('wr.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Conta quantas rotas pertencem a um parceiro.
     */
    public function countRoutesByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
