<?php

namespace App\Repository;

use App\Entity\WazeTvtRouteDefinition;
use App\Entity\WazeTvtRouteExecution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @deprecated Use WazeTvtRouteDefinitionRepository and WazeTvtRouteExecutionRepository instead.
 * This repository is kept for backwards compatibility during migration.
 */
class WazeTvtRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRouteExecution::class);
    }

    /**
     * Find the latest execution for a given route ID.
     */
    public function findLatestByRouteId(string $routeId): ?WazeTvtRouteExecution
    {
        $qb = $this->createQueryBuilder('e')
            ->join('e.routeDefinition', 'd')
            ->where('d.routeId = :routeId')
            ->orderBy('e.timestamp', 'DESC')
            ->setMaxResults(1);

        return $qb->getQuery()->setParameter('routeId', $routeId)->getOneOrNullResult();
    }

    /**
     * Find recent executions for a route.
     *
     * @return WazeTvtRouteExecution[]
     */
    public function findRecentByRouteId(string $routeId, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('e')
            ->join('e.routeDefinition', 'd')
            ->where('d.routeId = :routeId')
            ->orderBy('e.timestamp', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->setParameter('routeId', $routeId)->getResult();
    }

    /**
     * Get a route definition by ID (legacy method).
     */
    public function findOneByRouteId(string $routeId): ?WazeTvtRouteDefinition
    {
        return $this->getEntityManager()
            ->getRepository(WazeTvtRouteDefinition::class)
            ->findOneBy(['routeId' => $routeId]);
    }
}
