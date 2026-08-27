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
     * Busca alertas de um parceiro com filtros e paginacao.
     * Retorna array com items, total e pages.
     */
    public function findFilteredByPartner(
        object $partner,
        array $filters = [],
        int $page = 1,
        int $limit = 30
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);

        // Query para contar total
        $countQb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner);

        if ($filters['type'] !== null && $filters['type'] !== '') {
            $countQb->andWhere('a.type = :type')
                ->setParameter('type', $filters['type']);
        }

        if ($filters['subtype'] !== null && $filters['subtype'] !== '') {
            $countQb->andWhere('a.subtype = :subtype')
                ->setParameter('subtype', $filters['subtype']);
        }

        if ($filters['city'] !== null && $filters['city'] !== '') {
            $countQb->andWhere('a.city LIKE :city')
                ->setParameter('city', '%' . $filters['city'] . '%');
        }

        if ($filters['street'] !== null && $filters['street'] !== '') {
            $countQb->andWhere('a.street LIKE :street')
                ->setParameter('street', '%' . $filters['street'] . '%');
        }

        if ($filters['excludeStreet'] !== null && $filters['excludeStreet'] !== '') {
            $countQb->andWhere('a.street NOT LIKE :excludeStreet')
                ->setParameter('excludeStreet', '%' . $filters['excludeStreet'] . '%');
        }

        if ($filters['dateFrom'] !== null && $filters['dateFrom'] !== '') {
            $countQb->andWhere('a.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $filters['dateFrom']);
        }

        if ($filters['dateTo'] !== null && $filters['dateTo'] !== '') {
            $countQb->andWhere('a.createdAt <= :dateTo')
                ->setParameter('dateTo', $filters['dateTo']);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // Query para buscar items com paginacao
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

        $items = $qb->getQuery()->getResult();
        $pages = (int) ceil($total / $limit);

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
        ];
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

    /**
     * Count alerts by subtype filtered for a partner.
     */
    public function countBySubtypeFiltered(
        object $partner,
        array $filters = [],
        int $limit = 10
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->select('a.type, a.subtype, COUNT(a.id) as total')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->groupBy('a.type', 'a.subtype')
            ->orderBy('total', 'DESC')
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

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Count alerts grouped by day for a partner with filters.
     * Uses PHP grouping to avoid DQL function compatibility issues.
     */
    public function countByDayFiltered(
        object $partner,
        array $filters = []
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->select('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.createdAt', 'ASC');

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

        $alerts = $qb->getQuery()->getResult();

        $groupedByDay = [];
        foreach ($alerts as $alert) {
            $day = $alert->getCreatedAt()->format('Y-m-d');
            if (!isset($groupedByDay[$day])) {
                $groupedByDay[$day] = 0;
            }
            $groupedByDay[$day]++;
        }

        $result = [];
        foreach ($groupedByDay as $day => $total) {
            $result[] = [
                'day' => $day,
                'total' => $total,
            ];
        }

        return $result;
    }

    /**
     * Count alerts grouped by hour of day for a partner with filters.
     * Uses PHP grouping to avoid DQL function compatibility issues.
     */
    public function countByHourOfDayFiltered(
        object $partner,
        array $filters = []
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->select('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.createdAt', 'ASC');

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

        $alerts = $qb->getQuery()->getResult();

        $groupedByHour = [];
        foreach ($alerts as $alert) {
            $hour = $alert->getCreatedAt()->format('H');
            if (!isset($groupedByHour[$hour])) {
                $groupedByHour[$hour] = 0;
            }
            $groupedByHour[$hour]++;
        }

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $hour = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
            $result[] = [
                'hour' => $hour,
                'total' => $groupedByHour[$hour] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Count alerts by confidence filtered for a partner.
     */
    public function countByConfidenceFiltered(
        object $partner,
        array $filters = []
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->select('a.reliability, COUNT(a.id) as total')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->groupBy('a.reliability')
            ->orderBy('total', 'DESC');

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

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Count alerts by weekday filtered for a partner.
     */
    public function countByWeekdayFiltered(
        object $partner,
        array $filters = []
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->select('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.createdAt', 'ASC');

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

        $alerts = $qb->getQuery()->getResult();

        $weekdayNames = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
        $groupedByWeekday = [];
        foreach ($alerts as $alert) {
            $weekday = (int) $alert->getCreatedAt()->format('w');
            if (!isset($groupedByWeekday[$weekday])) {
                $groupedByWeekday[$weekday] = 0;
            }
            $groupedByWeekday[$weekday]++;
        }

        $result = [];
        for ($w = 0; $w < 7; $w++) {
            $result[] = [
                'weekday' => $weekdayNames[$w],
                'total' => $groupedByWeekday[$w] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Top streets filtered for a partner.
     */
    public function topStreetsFiltered(
        object $partner,
        array $filters = [],
        int $limit = 10
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->select('a.street, COUNT(a.id) as total')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->groupBy('a.street')
            ->orderBy('total', 'DESC')
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

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Find hotspots filtered for a partner.
     */
    public function findHotspotsFiltered(
        object $partner,
        array $filters = [],
        int $limit = 15
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->select('a.city, a.street, COUNT(a.id) as total')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->groupBy('a.city', 'a.street')
            ->orderBy('total', 'DESC')
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

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Find alerts for map filtered for a partner.
     */
    public function findForMapFiltered(
        object $partner,
        array $filters = [],
        int $limit = 500
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.createdAt', 'DESC')
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
     * Find distinct types for a partner.
     */
    public function findDistinctTypes(object $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.type')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.type', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Find distinct subtypes for a partner and type.
     */
    public function findDistinctSubtypes(object $partner, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT a.subtype')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner);

        if ($type !== null && $type !== '') {
            $qb->andWhere('a.type = :type')
                ->setParameter('type', $type);
        }

        return $qb->orderBy('a.subtype', 'ASC')->getQuery()->getSingleColumnResult();
    }

    /**
     * Find distinct cities for a partner.
     */
    public function findDistinctCities(object $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.city')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.city', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Find distinct streets for a partner.
     */
    public function findDistinctStreets(object $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.street')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.street', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Find active alerts by partner.
     */
    public function findActiveByPartner(object $partner, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
