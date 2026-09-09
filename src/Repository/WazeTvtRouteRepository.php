<?php

namespace App\Repository;

use App\Entity\WazeTvtRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeTvtRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRoute::class);
    }

    public function findOneByFeedAndExternalRouteId(int $feedId, string $externalRouteId): ?WazeTvtRoute
    {
        return $this->createQueryBuilder('r')
            ->join('r.wazeFeed', 'f')
            ->where('f.id = :feedId')
            ->andWhere('r.externalRouteId = :externalRouteId')
            ->setParameter('feedId', $feedId)
            ->setParameter('externalRouteId', $externalRouteId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveByFeed(int $feedId): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.wazeFeed', 'f')
            ->where('f.id = :feedId')
            ->andWhere('r.isActive = :active')
            ->setParameter('feedId', $feedId)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }
}
