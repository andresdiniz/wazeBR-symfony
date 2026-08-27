<?php

namespace App\Repository;

use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, WazeAlert::class);
    }

    /**
     * Busca alertas de um parceiro com filtros e paginação.
     */
    public function findFilteredByPartner(
        object $partner,
        array $filters = [],
        int $page = 1,
        int $limit = 30
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $qb = $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($filters['type'] !== null && $filters['type'] !== '') {
            $qb->andWhere('a.type = :type')
                ->setParameter('type', $filters['type']);
        }

        if ($filters['subtype'] !== null && $filters['subtype'] !== '') {
            $qb->andWhere('a.subtype = :subtype')
                ->setParameter('subtype', $filters['subtype']);
        }

        if ($filters['city'] !== null && $filters['city'] !== '') {
            $qb->andWhere('a.city LIKE :city')
                ->setParameter('city', '%' . $filters['city'] . '%');
        }

        if ($filters['street'] !== null && $filters['street'] !== '') {
            $qb->andWhere('a.street LIKE :street')
                ->setParameter('street', '%' . $filters['street'] . '%');
        }

        if ($filters['excludeStreet'] !== null && $filters['excludeStreet'] !== '') {
            $qb->andWhere('a.street NOT LIKE :excludeStreet')
                ->setParameter('excludeStreet', '%' . $filters['excludeStreet'] . '%');
        }

        if ($filters['dateFrom'] !== null && $filters['dateFrom'] !== '') {
            $qb->andWhere('a.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $filters['dateFrom']);
        }

        if ($filters['dateTo'] !== null && $filters['dateTo'] !== '') {
            $qb->andWhere('a.createdAt <= :dateTo')
                ->setParameter('dateTo', $filters['dateTo']);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count alerts in a period for a partner.
     */
    public function countInPeriod($partner, $startDate, $endDate): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.createdAt >= :startDate')
            ->andWhere('a.createdAt <= :endDate')
            ->setParameter('partner', $partner)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count alerts by subtype in a period for a partner.
     */
    public function countBySubtypeInPeriod($partner, $startDate, $endDate, $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.type, a.subtype, COUNT(a.id) as total')
            ->where('a.partner = :partner')
            ->andWhere('a.createdAt >= :startDate')
            ->andWhere('a.createdAt <= :endDate')
            ->setParameter('partner', $partner)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->groupBy('a.type', 'a.subtype')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }
}
