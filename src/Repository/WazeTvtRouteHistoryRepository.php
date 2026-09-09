<?php

namespace App\Repository;

use App\Entity\WazeTvtRouteHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeTvtRouteHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRouteHistory::class);
    }

    public function findRecentByRoute(int $routeId, int $limit = 50): array
    {
        return $this->createQueryBuilder('h')
            ->join('h.wazeTvtRoute', 'r')
            ->where('r.id = :routeId')
            ->setParameter('routeId', $routeId)
            ->orderBy('h.observedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByRouteInPeriod(int $routeId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('h')
            ->join('h.wazeTvtRoute', 'r')
            ->where('r.id = :routeId')
            ->andWhere('h.observedAt BETWEEN :from AND :to')
            ->setParameter('routeId', $routeId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('h.observedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
