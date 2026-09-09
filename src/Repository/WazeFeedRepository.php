<?php

namespace App\Repository;

use App\Entity\WazeFeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeFeed::class);
    }

    public function findActiveEventsFeeds(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.feedType = :type')
            ->andWhere('f.isActive = :active')
            ->setParameter('type', 'EVENTS')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    public function findActiveTvtFeeds(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.feedType = :type')
            ->andWhere('f.isActive = :active')
            ->setParameter('type', 'TVT')
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca feed por combinação única de partner + feedType + feedUuid + externalRouteId.
     */
    public function findOneByUniqueConstraint(
        int $partnerId,
        string $feedType,
        string $feedUuid,
        ?string $externalRouteId
    ): ?WazeFeed {
        $qb = $this->createQueryBuilder('f')
            ->join('f.partner', 'p')
            ->where('p.id = :partnerId')
            ->andWhere('f.feedType = :feedType')
            ->andWhere('f.feedUuid = :feedUuid')
            ->setParameter('partnerId', $partnerId)
            ->setParameter('feedType', $feedType)
            ->setParameter('feedUuid', $feedUuid);

        if ($externalRouteId === null) {
            $qb->andWhere('f.externalRouteId IS NULL');
        } else {
            $qb->andWhere('f.externalRouteId = :externalRouteId')
               ->setParameter('externalRouteId', $externalRouteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Retorna todos os feeds de um partner, com eager-load do partner.
     */
    public function findByPartner(int $partnerId): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.partner', 'p')
            ->where('p.id = :partnerId')
            ->setParameter('partnerId', $partnerId)
            ->orderBy('f.feedType', 'ASC')
            ->addOrderBy('f.label', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
