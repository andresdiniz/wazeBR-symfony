<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeRoute;
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

    public function save(WazeRouteSnapshot $snapshot, bool $flush = true): void
    {
        $this->getEntityManager()->persist($snapshot);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Retorna os últimos N snapshots de uma rota (mais recentes primeiro).
     *
     * @return WazeRouteSnapshot[]
     */
    public function findLatestByRoute(WazeRoute $route, int $limit = 50): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.route = :route')
            ->setParameter('route', $route)
            ->orderBy('s.collectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna snapshots de uma rota dentro de um intervalo de datas.
     *
     * @return WazeRouteSnapshot[]
     */
    public function findByRouteBetween(WazeRoute $route, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.route = :route')
            ->andWhere('s.collectedAt BETWEEN :from AND :to')
            ->setParameter('route', $route)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.collectedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
