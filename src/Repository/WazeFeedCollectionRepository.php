<?php

namespace App\Repository;

use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeFeedCollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeFeedCollection::class);
    }

    public function createForFeed(WazeFeed $feed): WazeFeedCollection
    {
        $collection = new WazeFeedCollection();
        $collection->setWazeFeed($feed);
        $this->getEntityManager()->persist($collection);
        $this->getEntityManager()->flush();
        return $collection;
    }

    /**
     * Últimas N coletas de um feed, ordenadas por data decrescente.
     */
    public function findRecentByFeed(int $feedId, int $limit = 10): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.wazeFeed', 'f')
            ->where('f.id = :feedId')
            ->setParameter('feedId', $feedId)
            ->orderBy('c.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Coletas com falha nas últimas X horas.
     */
    public function findFailedSince(\DateTimeInterface $since): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.startedAt >= :since')
            ->setParameter('status', 'FAILED')
            ->setParameter('since', $since)
            ->orderBy('c.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
