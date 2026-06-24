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

    /** Retorna todos os feeds ativos de alertas Waze (type = feed) */
    public function findActiveWazeFeeds(): array
    {
        return $this->createQueryBuilder('ml')
            ->join('ml.partner', 'p')
            ->where('ml.type = :type')
            ->andWhere('ml.isActive = true')
            ->andWhere('p.isActive = true')
            ->setParameter('type', 'feed')
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('ml.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Retorna feeds ativos de um parceiro específico */
    public function findActiveFeedsByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('ml')
            ->where('ml.partner = :partner')
            ->andWhere('ml.type = :type')
            ->andWhere('ml.isActive = true')
            ->setParameter('partner', $partner)
            ->setParameter('type', 'feed')
            ->getQuery()
            ->getResult();
    }

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
}
