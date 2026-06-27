<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeRouteSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeRouteSnapshot>
 */
class WazeRouteSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeRouteSnapshot::class);
    }

    /**
     * Retorna os últimos N snapshots de uma rota, ordenados do mais recente ao mais antigo.
     *
     * @return WazeRouteSnapshot[]
     */
    public function findLatestByRoute(int $routeId, int $limit = 48): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.route = :routeId')
            ->setParameter('routeId', $routeId)
            ->orderBy('s.collectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
