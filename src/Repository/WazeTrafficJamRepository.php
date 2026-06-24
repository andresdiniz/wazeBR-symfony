<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeTrafficJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTrafficJam::class);
    }

    public function findRecentJams(int $hours = 2): array
    {
        $since = (int) ((time() - $hours * 3600) * 1000);
        return $this->createQueryBuilder('j')
            ->where('j.pubMillis > :since')
            ->setParameter('since', $since)
            ->orderBy('j.level', 'DESC')
            ->getQuery()->getResult();
    }

    public function findFiltered(int $hours = 2, ?string $city = null, ?int $level = null): array
    {
        $since = (int) ((time() - $hours * 3600) * 1000);
        $qb = $this->createQueryBuilder('j')
            ->where('j.pubMillis > :since')
            ->setParameter('since', $since);

        if ($city) {
            $qb->andWhere('j.city LIKE :city')->setParameter('city', "%{$city}%");
        }
        if ($level !== null) {
            $qb->andWhere('j.level >= :level')->setParameter('level', $level);
        }

        return $qb->orderBy('j.level', 'DESC')->getQuery()->getResult();
    }

    public function countByDate(\DateTimeImmutable $date): int
    {
        $end = $date->modify('+1 day');
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.createdAt >= :start AND j.createdAt < :end')
            ->setParameter('start', $date)
            ->setParameter('end', $end)
            ->getQuery()->getSingleScalarResult();
    }

    public function countGroupedByLevel(\DateTimeImmutable $date): array
    {
        $end = $date->modify('+1 day');
        return $this->createQueryBuilder('j')
            ->select('j.level, COUNT(j.id) as total')
            ->where('j.createdAt >= :start AND j.createdAt < :end')
            ->setParameter('start', $date)
            ->setParameter('end', $end)
            ->groupBy('j.level')
            ->orderBy('j.level', 'DESC')
            ->getQuery()->getArrayResult();
    }
}
