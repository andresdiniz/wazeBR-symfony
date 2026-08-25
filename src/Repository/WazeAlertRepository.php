<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, WazeAlert::class); }

    private function applyFilters(QueryBuilder $qb, Partner $partner, array $filters): void
    {
        $qb->andWhere('a.partner = :partner')->setParameter('partner', $partner);
        foreach (['type', 'subtype'] as $field) {
            if (!empty($filters[$field])) $qb->andWhere("a.$field = :$field")->setParameter($field, $filters[$field]);
        }
        foreach (['city', 'street'] as $field) {
            if (!empty($filters[$field])) $qb->andWhere("LOWER(a.$field) LIKE :$field")->setParameter($field, '%' . mb_strtolower($filters[$field]) . '%');
        }
        if (!empty($filters['excludeStreet'])) {
            foreach (array_filter(array_map('trim', explode(',', $filters['excludeStreet']))) as $i => $term) {
                $name = 'excludeStreet' . $i;
                $qb->andWhere("LOWER(a.street) NOT LIKE :$name")->setParameter($name, '%' . mb_strtolower($term) . '%');
            }
        }
        $timezone = new \DateTimeZone('America/Sao_Paulo');
        if (!empty($filters['dateFrom'])) {
            $from = new \DateTimeImmutable($filters['dateFrom'], $timezone);
            $qb->andWhere('a.pubMillis >= :dateFrom')->setParameter('dateFrom', $from->setTime(0, 0, 0)->getTimestamp() * 1000);
        }
        if (!empty($filters['dateTo'])) {
            $to = new \DateTimeImmutable($filters['dateTo'], $timezone);
            $qb->andWhere('a.pubMillis <= :dateTo')->setParameter('dateTo', $to->setTime(23, 59, 59)->getTimestamp() * 1000);
        }
    }
}
