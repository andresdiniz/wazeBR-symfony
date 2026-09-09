<?php

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

    public function findOneByExternalUuid(int $partnerId, int $feedId, string $externalUuid): ?WazeAlert
    {
        return $this->createQueryBuilder('a')
            ->join('a.partner', 'p')
            ->join('a.wazeFeed', 'f')
            ->where('p.id = :partnerId')
            ->andWhere('f.id = :feedId')
            ->andWhere('a.externalUuid = :uuid')
            ->setParameter('partnerId', $partnerId)
            ->setParameter('feedId', $feedId)
            ->setParameter('uuid', $externalUuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByDedupKey(int $partnerId, string $dedupKey): ?WazeAlert
    {
        return $this->createQueryBuilder('a')
            ->join('a.partner', 'p')
            ->where('p.id = :partnerId')
            ->andWhere('a.dedupKey = :dedupKey')
            ->setParameter('partnerId', $partnerId)
            ->setParameter('dedupKey', $dedupKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveByFeed(int $feedId): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.wazeFeed', 'f')
            ->where('f.id = :feedId')
            ->andWhere('a.isActive = :active')
            ->setParameter('feedId', $feedId)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Alertas ativos que não foram vistos desde $cutoff (candidatos a "missing").
     */
    public function findMissingSince(int $feedId, \DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.wazeFeed', 'f')
            ->where('f.id = :feedId')
            ->andWhere('a.isActive = :active')
            ->andWhere('a.lastSeenAt < :cutoff')
            ->andWhere('a.missingSinceAt IS NULL')
            ->setParameter('feedId', $feedId)
            ->setParameter('active', true)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Alertas ausentes há tempo suficiente para serem desativados.
     */
    public function findExpired(int $feedId, \DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.wazeFeed', 'f')
            ->where('f.id = :feedId')
            ->andWhere('a.isActive = :active')
            ->andWhere('a.missingSinceAt IS NOT NULL')
            ->andWhere('a.missingSinceAt < :cutoff')
            ->setParameter('feedId', $feedId)
            ->setParameter('active', true)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
