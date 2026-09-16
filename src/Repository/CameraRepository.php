<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Camera;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Camera>
 */
final class CameraRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Camera::class);
    }

    /**
     * Câmeras ativas do parceiro, ordenadas por sortOrder.
     * Se $partner for null, retorna todas (uso do admin global).
     *
     * @return Camera[]
     */
    public function findActiveByPartner(?Partner $partner): array
    {
        $qb = $this->baseOrderedQuery()
            ->andWhere('c.active = :active')
            ->setParameter('active', true);

        $this->scopePartner($qb, $partner);

        return $qb->getQuery()->getResult();
    }

    /**
     * Todas as câmeras do parceiro (ativas + inativas).
     * Para telas de administração.
     *
     * @return Camera[]
     */
    public function findByPartner(?Partner $partner): array
    {
        $qb = $this->baseOrderedQuery();
        $this->scopePartner($qb, $partner);

        return $qb->getQuery()->getResult();
    }

    public function countActiveByPartner(?Partner $partner): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.active = :active')
            ->setParameter('active', true);

        $this->scopePartner($qb, $partner);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Busca uma câmera específica garantindo que pertence ao parceiro.
     * Usado em rotas de edição/remoção.
     */
    public function findOneByPartnerAndId(?Partner $partner, int $id): ?Camera
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->setParameter('id', $id);

        $this->scopePartner($qb, $partner);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Última posição da fila — útil quando o admin cria uma nova
     * câmera sem informar sortOrder, colocamos ela no fim.
     */
    public function getNextSortOrder(Partner $partner): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.sortOrder)')
            ->andWhere('c.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max + 10);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function baseOrderedQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.sortOrder', 'ASC')
            ->addOrderBy('c.id', 'ASC');
    }

    private function scopePartner(QueryBuilder $qb, ?Partner $partner): void
    {
        if ($partner !== null) {
            $qb->andWhere('c.partner = :partner')
               ->setParameter('partner', $partner);
        }
    }
}
