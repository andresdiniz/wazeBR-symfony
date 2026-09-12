<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenHidroStationLink;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CemadenHidroStationLink>
 */
class CemadenHidroStationLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenHidroStationLink::class);
    }

    /**
     * Retorna todas as stations hidro ativas de um partner.
     *
     * @return CemadenHidroStationLink[]
     */
    public function findActiveByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('chsl')
            ->where('chsl.partner = :partner')
            ->andWhere('chsl.active = true')
            ->setParameter('partner', $partner)
            ->orderBy('chsl.stationName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna todas as stations hidro ativas de todos os partners.
     * Eager-load do partner para evitar N+1.
     *
     * @return CemadenHidroStationLink[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('chsl')
            ->addSelect('p')
            ->join('chsl.partner', 'p')
            ->where('chsl.active = true')
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('chsl.stationName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna stations ativas cujo lastFetchedAt é anterior ao threshold informado
     * (ou nunca foram coletadas).
     *
     * Exemplo: $threshold = new \DateTimeImmutable('-1 hour')
     *   → coleta apenas quem não foi buscado na última hora.
     *
     * @return CemadenHidroStationLink[]
     */
    public function findDueForCollection(\DateTimeImmutable $threshold): array
    {
        return $this->createQueryBuilder('chsl')
            ->addSelect('p')
            ->join('chsl.partner', 'p')
            ->where('chsl.active = true')
            ->andWhere('chsl.lastFetchedAt IS NULL OR chsl.lastFetchedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('chsl.lastFetchedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna stations ativas de um partner cujo lastFetchedAt é anterior ao threshold.
     *
     * @return CemadenHidroStationLink[]
     */
    public function findDueForCollectionByPartner(
        Partner $partner,
        \DateTimeImmutable $threshold,
    ): array {
        return $this->createQueryBuilder('chsl')
            ->where('chsl.partner = :partner')
            ->andWhere('chsl.active = true')
            ->andWhere('chsl.lastFetchedAt IS NULL OR chsl.lastFetchedAt < :threshold')
            ->setParameter('partner', $partner)
            ->setParameter('threshold', $threshold)
            ->orderBy('chsl.lastFetchedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
