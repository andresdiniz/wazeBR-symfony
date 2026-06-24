<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredLink;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoredLink>
 */
class MonitoredLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoredLink::class);
    }

    public function save(MonitoredLink $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(MonitoredLink $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Todos os feeds de alertas Waze ativos (type = feed) */
    public function findActiveWazeFeeds(): array
    {
        return $this->findActiveByType('feed');
    }

    /** Todos os feeds TVT de tr\u00e1fego ativos (type = traffic) */
    public function findActiveTrafficFeeds(): array
    {
        return $this->findActiveByType('traffic');
    }

    /** Feeds ativos por tipo */
    public function findActiveByType(string $type): array
    {
        return $this->createQueryBuilder('ml')
            ->join('ml.partner', 'p')
            ->where('ml.type = :type')
            ->andWhere('ml.isActive = true')
            ->andWhere('p.isActive = true')
            ->setParameter('type', $type)
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('ml.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Todos os links de um parceiro */
    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('ml')
            ->where('ml.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('ml.type', 'ASC')
            ->addOrderBy('ml.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Links de feeds Waze ativos de um parceiro espec\u00edfico */
    public function findActiveFeedsByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('ml')
            ->where('ml.partner = :partner')
            ->andWhere('ml.type IN (:types)')
            ->andWhere('ml.isActive = true')
            ->setParameter('partner', $partner)
            ->setParameter('types', ['feed', 'traffic'])
            ->getQuery()
            ->getResult();
    }
}
