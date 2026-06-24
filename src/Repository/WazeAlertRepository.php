<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    public function findRecentAlerts(int $hours = 2): array
    {
        $since = (int) ((time() - $hours * 3600) * 1000);
        return $this->createQueryBuilder('a')
            ->where('a.pubMillis > :since')
            ->setParameter('since', $since)
            ->orderBy('a.pubMillis', 'DESC')
            ->getQuery()->getResult();
    }

    public function findFiltered(int $hours = 2, ?string $city = null, ?string $type = null): array
    {
        $since = (int) ((time() - $hours * 3600) * 1000);
        $qb = $this->createQueryBuilder('a')
            ->where('a.pubMillis > :since')
            ->setParameter('since', $since);

        if ($city) {
            $qb->andWhere('a.city LIKE :city')->setParameter('city', "%{$city}%");
        }
        if ($type) {
            $qb->andWhere('a.type = :type')->setParameter('type', strtoupper($type));
        }

        return $qb->orderBy('a.pubMillis', 'DESC')->getQuery()->getResult();
    }

    public function findHighRiskAlerts(): array
    {
        $since = (int) ((time() - 1800) * 1000);
        return $this->createQueryBuilder('a')
            ->where('a.pubMillis > :since')
            ->andWhere('a.reliability >= :rel')
            ->setParameter('since', $since)
            ->setParameter('rel', 8)
            ->orderBy('a.reliability', 'DESC')
            ->getQuery()->getResult();
    }

    public function countByDate(\DateTimeImmutable $date): int
    {
        $end = $date->modify('+1 day');
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt >= :start AND a.createdAt < :end')
            ->setParameter('start', $date)
            ->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }

    public function countGroupedByType(\DateTimeImmutable $date): array
    {
        $end = $date->modify('+1 day');
        return $this->createQueryBuilder('a')
            ->select('a.type, COUNT(a.id) as total')
            ->where('a.createdAt >= :start AND a.createdAt < :end')
            ->setParameter('start', $date)
            ->setParameter('end', $end)
            ->groupBy('a.type')
            ->orderBy('total', 'DESC')
            ->getQuery()->getArrayResult();
    }

    public function findLastCreatedAt(): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('a')
            ->select('a.createdAt')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        return $result ? $result['createdAt'] : null;
    }
}
