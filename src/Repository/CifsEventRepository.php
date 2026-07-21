<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CifsEvent;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CifsEvent>
 */
class CifsEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CifsEvent::class);
    }

    // ── Listagem filtrada (usada pelo CifsEventController) ────────────────────

    /**
     * Lista eventos com filtros opcionais.
     *
     * @param bool     $onlyActive  Se true, retorna apenas eventos ativos agora.
     * @param int      $limit       Número máximo de resultados.
     * @param Partner|null $partner Filtra por parceiro (null = todos).
     * @return CifsEvent[]
     */
    public function findFiltered(
        bool     $onlyActive = false,
        int      $limit      = 50,
        ?Partner $partner    = null,
    ): array {
        $now = new \DateTimeImmutable();
        $qb  = $this->createQueryBuilder('e')
            ->orderBy('e.startDate', 'DESC')
            ->setMaxResults($limit);

        if ($onlyActive) {
            $qb->andWhere('e.startDate <= :now')
               ->andWhere('e.endDate IS NULL OR e.endDate > :now')
               ->setParameter('now', $now);
        }

        if ($partner !== null) {
            $qb->andWhere('e.partner = :partner')
               ->setParameter('partner', $partner);
        }

        return $qb->getQuery()->getResult();
    }

    // ── KPIs ──────────────────────────────────────────────────────────────────

    /**
     * Eventos ativos agora (endDate > NOW() ou sem endDate).
     */
    public function findActiveByPartner(Partner $partner): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('e')
            ->where('e.partner = :partner')
            ->andWhere('e.endDate IS NULL OR e.endDate > :now')
            ->andWhere('e.startDate <= :now')
            ->setParameter('partner', $partner)
            ->setParameter('now', $now)
            ->orderBy('e.startDate', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Eventos programados para os próximos $days dias (não iniciados ainda).
     */
    public function findUpcomingByPartner(Partner $partner, int $days = 7): array
    {
        $now    = new \DateTimeImmutable();
        $future = new \DateTimeImmutable("+{$days} days");

        return $this->createQueryBuilder('e')
            ->where('e.partner = :partner')
            ->andWhere('e.startDate > :now')
            ->andWhere('e.startDate <= :future')
            ->setParameter('partner', $partner)
            ->setParameter('now', $now)
            ->setParameter('future', $future)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Contagem de eventos ativos por tipo (CONSTRUCTION, ROAD_CLOSED, etc.).
     * Retorna [['type'=>'CONSTRUCTION','total'=>3], ...]
     */
    public function countActiveByType(Partner $partner): array
    {
        $now = new \DateTimeImmutable();

        $rows = $this->createQueryBuilder('e')
            ->select('e.type AS type, COUNT(e.id) AS total')
            ->where('e.partner = :partner')
            ->andWhere('e.endDate IS NULL OR e.endDate > :now')
            ->andWhere('e.startDate <= :now')
            ->setParameter('partner', $partner)
            ->setParameter('now', $now)
            ->groupBy('e.type')
            ->orderBy('total', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => ['type' => $r['type'], 'total' => (int)$r['total']], $rows);
    }

    /**
     * Vias com maior concentração de eventos ativos simultâneos.
     * Retorna [['street'=>'Av. Brasil','total'=>3], ...]
     */
    public function topStreetsByActiveEvents(Partner $partner, int $limit = 10): array
    {
        $now = new \DateTimeImmutable();

        $rows = $this->createQueryBuilder('e')
            ->select('e.street AS street, COUNT(e.id) AS total')
            ->where('e.partner = :partner')
            ->andWhere('e.endDate IS NULL OR e.endDate > :now')
            ->andWhere('e.startDate <= :now')
            ->andWhere('e.street IS NOT NULL')
            ->setParameter('partner', $partner)
            ->setParameter('now', $now)
            ->groupBy('e.street')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_map(static fn($r) => ['street' => $r['street'], 'total' => (int)$r['total']], $rows);
    }

    /**
     * Total de eventos ativos agora.
     */
    public function countActive(Partner $partner): int
    {
        return count($this->findActiveByPartner($partner));
    }
}
