<?php

namespace App\Repository;

use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeAlert>
 */
class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    public function countInPeriod(\DateTimeInterface $start, \DateTimeInterface $end, ?int $partnerId = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.lastSeenAt >= :start')
            ->andWhere('a.lastSeenAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($partnerId !== null) {
            $qb->andWhere('a.partner = :partner')
               ->setParameter('partner', $partnerId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findActiveByPartner(int $partnerId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->andWhere('a.isActive = true')
            ->setParameter('partner', $partnerId)
            ->orderBy('a.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
