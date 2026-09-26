<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrafficLight;
use App\Entity\TrafficLightSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class TrafficLightSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrafficLightSnapshot::class);
    }

    public function findLatestForLight(TrafficLight $light): ?TrafficLightSnapshot
    {
        return $this->createQueryBuilder('s')
            ->where('s.trafficLight = :light')
            ->setParameter('light', $light)
            ->orderBy('s.readAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return TrafficLightSnapshot[] */
    public function findHistoryForLight(
        TrafficLight $light,
        int $limit = 100,
        ?\DateTimeImmutable $since = null,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->where('s.trafficLight = :light')
            ->setParameter('light', $light)
            ->orderBy('s.readAt', 'DESC')
            ->setMaxResults($limit);

        if ($since !== null) {
            $qb->andWhere('s.readAt >= :since')->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /** Remove snapshots mais antigos que o cutoff. Retorna nº de linhas apagadas. */
    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.readAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
