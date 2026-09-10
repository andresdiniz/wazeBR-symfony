<?php

namespace App\Repository;

use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTrafficJam>
 */
class WazeTrafficJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTrafficJam::class);
    }

    public function countInPeriod(\DateTimeInterface $start, \DateTimeInterface $end, ?int $partnerId = null): int
    {
        $qb = $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.lastSeenAt >= :start')
            ->andWhere('j.lastSeenAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($partnerId !== null) {
            $qb->andWhere('j.partner = :partner')
               ->setParameter('partner', $partnerId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findActiveByPartner(int $partnerId): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.partner = :partner')
            ->andWhere('j.isActive = true')
            ->setParameter('partner', $partnerId)
            ->orderBy('j.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
