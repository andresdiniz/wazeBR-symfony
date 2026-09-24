<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\PartnerFeedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PartnerFeedEvent>
 */
final class PartnerFeedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerFeedEvent::class);
    }

    /**
     * Eventos ativos de um parceiro dentro do intervalo de tempo válido.
     * Usado pelo PartnerFeedService para montar o feed.json.
     *
     * @return PartnerFeedEvent[]
     */
    public function findActiveByPartner(Partner $partner): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('e')
            ->where('e.partner = :partner')
            ->andWhere('e.status = :status')
            ->andWhere('e.starttime <= :now')
            ->andWhere('e.endtime IS NULL OR e.endtime >= :now')
            ->setParameter('partner', $partner)
            ->setParameter('status', PartnerFeedEvent::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->orderBy('e.starttime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Listagem paginada para o painel (admin / editor).
     *
     * @return PartnerFeedEvent[]
     */
    public function findByPartnerPaginated(
        Partner $partner,
        int $page = 1,
        int $limit = 25,
    ): array {
        return $this->createQueryBuilder('e')
            ->where('e.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('e.createdAt', 'DESC')
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

    /**
     * Eventos com endtime no passado ainda marcados como ativos.
     * Usado pelo comando partner-feed:expire.
     *
     * @return PartnerFeedEvent[]
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.endtime IS NOT NULL')
            ->andWhere('e.endtime < :now')
            ->andWhere('e.status = :status')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('status', PartnerFeedEvent::STATUS_ACTIVE)
            ->getQuery()
            ->getResult();
    }
}
