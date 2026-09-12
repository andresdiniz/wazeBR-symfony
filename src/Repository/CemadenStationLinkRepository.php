<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenStationLink;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CemadenStationLink>
 */
class CemadenStationLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenStationLink::class);
    }

    /**
     * Retorna todas as stations pluviométricas ativas de um partner.
     *
     * @return CemadenStationLink[]
     */
    public function findActiveByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('csl')
            ->where('csl.partner = :partner')
            ->andWhere('csl.active = true')
            ->setParameter('partner', $partner)
            ->orderBy('csl.stationName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna todas as stations ativas de todos os partners.
     * Eager-load do partner para evitar N+1.
     *
     * @return CemadenStationLink[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('csl')
            ->addSelect('p')
            ->join('csl.partner', 'p')
            ->where('csl.active = true')
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('csl.stationName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna stations ativas cujo lastFetchedAt é anterior ao threshold informado
     * (ou nunca foram coletadas).
     *
     * Útil para evitar re-fetch dentro da janela mínima de atualização:
     *   $threshold = new \DateTimeImmutable('-1 hour')
     *   → coleta apenas quem não foi buscado na última hora.
     *
     * @return CemadenStationLink[]
     */
    public function findDueForCollection(\DateTimeImmutable $threshold): array
    {
        return $this->createQueryBuilder('csl')
            ->addSelect('p')
            ->join('csl.partner', 'p')
            ->where('csl.active = true')
            ->andWhere('csl.lastFetchedAt IS NULL OR csl.lastFetchedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('csl.lastFetchedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna stations ativas de um partner cujo lastFetchedAt é anterior ao threshold.
     *
     * @return CemadenStationLink[]
     */
    public function findDueForCollectionByPartner(
        Partner $partner,
        \DateTimeImmutable $threshold,
    ): array {
        return $this->createQueryBuilder('csl')
            ->where('csl.partner = :partner')
            ->andWhere('csl.active = true')
            ->andWhere('csl.lastFetchedAt IS NULL OR csl.lastFetchedAt < :threshold')
            ->setParameter('partner', $partner)
            ->setParameter('threshold', $threshold)
            ->orderBy('csl.lastFetchedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
