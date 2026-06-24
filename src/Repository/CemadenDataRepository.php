<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CemadenDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenData::class);
    }

    public function findActiveAlerts(): array
    {
        $since = new \DateTimeImmutable('-6 hours');
        return $this->createQueryBuilder('c')
            ->where('c.measuredAt >= :since')
            ->andWhere('c.alertLevel IS NOT NULL')
            ->setParameter('since', $since)
            ->orderBy('c.measuredAt', 'DESC')
            ->getQuery()->getResult();
    }

    public function countActiveAlerts(): int
    {
        $since = new \DateTimeImmutable('-6 hours');
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.measuredAt >= :since')
            ->andWhere('c.alertLevel IS NOT NULL AND c.alertLevel != :none')
            ->setParameter('since', $since)
            ->setParameter('none', 'VERDE')
            ->getQuery()->getSingleScalarResult();
    }
}
