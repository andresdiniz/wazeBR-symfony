<?php

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

    public function findOneByExternalId(int $partnerId, int $feedId, int $externalId): ?WazeTrafficJam
    {
        return $this->createQueryBuilder('j')
            ->join('j.partner', 'p')
            ->join('j.wazeFeed', 'f')
            ->where('p.id = :partnerId')
            ->andWhere('f.id = :feedId')
            ->andWhere('j.externalId = :externalId')
            ->setParameter('partnerId', $partnerId)
            ->setParameter('feedId', $feedId)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByDedupKey(int $partnerId, string $dedupKey): ?WazeTrafficJam
    {
        return $this->createQueryBuilder('j')
            ->join('j.partner', 'p')
            ->where('p.id = :partnerId')
            ->andWhere('j.dedupKey = :dedupKey')
            ->setParameter('partnerId', $partnerId)
            ->setParameter('dedupKey', $dedupKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveByFeed(int $feedId): array
    {
        return $this->createQueryBuilder('j')
            ->join('j.wazeFeed', 'f')
            ->where('f.id = :feedId')
            ->andWhere('j.isActive = :active')
            ->setParameter('feedId', $feedId)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    public function findMissingSince(int $feedId, \DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('j')
            ->join('j.wazeFeed', 'f')
            ->where('f.id = :feedId')
            ->andWhere('j.isActive = :active')
            ->andWhere('j.lastSeenAt < :cutoff')
            ->andWhere('j.missingSinceAt IS NULL')
            ->setParameter('feedId', $feedId)
            ->setParameter('active', true)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    public function findExpired(int $feedId, \DateTimeInterface $cutoff): array
    {
        return $this->createQueryBuilder('j')
            ->join('j.wazeFeed', 'f')
            ->where('f.id = :feedId')
            ->andWhere('j.isActive = :active')
            ->andWhere('j.missingSinceAt IS NOT NULL')
            ->andWhere('j.missingSinceAt < :cutoff')
            ->setParameter('feedId', $feedId)
            ->setParameter('active', true)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
