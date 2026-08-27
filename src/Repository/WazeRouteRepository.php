<?php

namespace App\Repository;

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

    public function findOneActiveByLink(string $link): ?WazeRoute
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
}
