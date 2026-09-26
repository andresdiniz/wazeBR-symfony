<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\PartnerFeedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PartnerFeedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerFeedEvent::class);
    }

    /** @return PartnerFeedEvent[] */
    public function findActiveByPartner(Partner $partner): array
    {
        $now = new \DateTimeImmutable();
        return $this->createQueryBuilder('e')
            ->where('e.partner = :partner')
            ->andWhere('e.isActive = :active')
            ->andWhere('e.startTime IS NULL OR e.startTime <= :now')
            ->andWhere('e.endTime IS NULL OR e.endTime >= :now')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('e.startTime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return PartnerFeedEvent[] */
    public function findByPartnerPaginated(Partner $partner, int $page = 1, int $limit = 25): array
    {
        $page = max(1, $page);
        return $this->createQueryBuilder('e')
            ->where('e.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('e.startTime', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return PartnerFeedEvent[] */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.endTime IS NOT NULL')
            ->andWhere('e.endTime < :now')
            ->andWhere('e.isActive = :active')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }
}
