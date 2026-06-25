<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredLink;
use App\Entity\Partner;
use App\Entity\WazeTvtSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTvtSnapshot>
 */
class WazeTvtSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtSnapshot::class);
    }

    /**
     * Conta o total de snapshots de um parceiro.
     */
    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retorna todos os snapshots de um parceiro, do mais recente ao mais antigo.
     */
    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('s.collectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Último snapshot de um link monitorado.
     */
    public function findLatestByLink(MonitoredLink $link): ?WazeTvtSnapshot
    {
        return $this->createQueryBuilder('s')
            ->where('s.sourceLink = :link')
            ->setParameter('link', $link)
            ->orderBy('s.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Snapshots de um parceiro nas últimas N horas.
     */
    public function findRecentByPartner(Partner $partner, int $hours = 24): array
    {
        $since = new \DateTimeImmutable("-{$hours} hours");

        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner')
            ->andWhere('s.collectedAt >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('s.collectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Conta snapshots por link nas últimas N horas.
     */
    public function countRecentByLink(MonitoredLink $link, int $hours = 1): int
    {
        $since = new \DateTimeImmutable("-{$hours} hours");

        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.sourceLink = :link')
            ->andWhere('s.collectedAt >= :since')
            ->setParameter('link', $link)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
