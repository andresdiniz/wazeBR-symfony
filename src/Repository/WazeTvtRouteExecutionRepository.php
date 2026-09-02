<?php

namespace App\Repository;

use App\Entity\WazeTvtRouteExecution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeTvtRouteExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRouteExecution::class);
    }

    /**
     * @return WazeTvtRouteExecution[]
     */
    public function findByRouteId(string $routeId, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->join('e.routeDefinition', 'd')
            ->where('d.routeId = :routeId')
            ->orderBy('e.timestamp', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->setParameter('routeId', $routeId)->getResult();
    }
}
