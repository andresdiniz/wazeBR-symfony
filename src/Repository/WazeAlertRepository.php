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

    public function findFilteredByPartner(Partner $partner, array $filters = [], int $page = 1, int $limit = 30): array { $base = $this->createQueryBuilder('a'); $this->applyFilters($base, $partner, $filters); $total = (int) (clone $base)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult(); $pages = max(1, (int) ceil($total / $limit)); $page = min(max(1, $page), $pages); $items = $base->orderBy('a.pubMillis', 'DESC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult(); return ['items' => $items, 'total' => $total, 'pages' => $pages]; }
    public function countFilteredByPartner(Partner $partner, array $filters = []): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        $this->applyFilters($qb, $partner, $filters);
        return (int) $qb->getQuery()->getSingleScalarResult();
    }
    public function iterateFilteredByPartnerForExport(Partner $partner, array $filters = [], int $limit = 200000): iterable
    {
        $qb = $this->createQueryBuilder('a');
        $this->applyFilters($qb, $partner, $filters);
        return $qb->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->toIterable();
    }
    public function findActiveByPartner(Partner $partner, int $minutes = 10): array { return $this->createQueryBuilder('a')->where('a.partner = :partner')->andWhere('a.pubMillis >= :since')->setParameter('partner', $partner)->setParameter('since', (time() - max(1, $minutes) * 60) * 1000)->orderBy('a.pubMillis', 'DESC')->getQuery()->getResult(); }

    // ─── Dashboard methods ─────────────────────────────────────────────────────

    /**
     * Total de alertas num período (usando pubMillis).
     */
    public function countInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from->getTimestamp() * 1000)
            ->setParameter('to', $to->getTimestamp() * 1000)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Contagem agrupada por subtipo num período (gráfico).
     */
    public function countBySubtypeInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.subType AS label, COUNT(a.id) AS count')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', $from->getTimestamp() * 1000)
            ->setParameter('to', $to->getTimestamp() * 1000)
            ->groupBy('a.subType')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Últimos N alertas de um parceiro.
     */
    public function findRecentByPartner(Partner $partner, int $limit = 30): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Alertas para o mapa com filtros.
     */
    public function findForMapFiltered(Partner $partner, array $filters = [], int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.id, a.type, a.subtype, a.street, a.city, a.latitude, a.longitude, a.confidence, a.nThumbsUp, a.pubMillis')
            ->andWhere('a.latitude IS NOT NULL')
            ->andWhere('a.longitude IS NOT NULL')
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit);
        $this->applyFilters($qb, $partner, $filters);
        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Total de alertas de um parceiro (todos os tempos).
     */
    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ─── Existing methods (mantidos) ──────────────────────────────────────────

    public function findCriticalByPartner(Partner $partner, int $minReliability = 8, int $windowMinutes = 30): array
    {
        $since = (time() - max(1, $windowMinutes) * 60) * 1000;
        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->andWhere('a.reliability >= :minReliability')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('minReliability', $minReliability)
            ->setParameter('since', $since)
            ->orderBy('a.pubMillis', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countLastHoursByPartner(Partner $partner, int $hours): int
    {
        $since = (time() - max(1, $hours) * 3600) * 1000;
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countLast1hByPartner(Partner $partner): int { return $this->countLastHoursByPartner($partner, 1); }
    public function countLast24hByPartner(Partner $partner): int { return $this->countLastHoursByPartner($partner, 24); }

    public function findOneByPartner(int $id, Partner $partner): ?WazeAlert { return $this->createQueryBuilder('a')->where('a.id = :id')->andWhere('a.partner = :partner')->setParameter('id', $id)->setParameter('partner', $partner)->getQuery()->getOneOrNullResult(); }

    public function findDistinctTypes(Partner $partner): array { return array_column($this->createQueryBuilder('a')->select('DISTINCT a.type AS type')->where('a.partner = :partner')->setParameter('partner', $partner)->orderBy('a.type')->getQuery()->getArrayResult(), 'type'); }
    public function findDistinctSubtypes(Partner $partner, ?string $type = null): array { $qb = $this->createQueryBuilder('a')->select('DISTINCT a.subtype AS subtype')->where('a.partner = :partner')->andWhere('a.subtype IS NOT NULL')->setParameter('partner', $partner)->orderBy('a.subtype'); if ($type) $qb->andWhere('a.type = :type')->setParameter('type', $type); return array_column($qb->getQuery()->getArrayResult(), 'subtype'); }
    public function findDistinctCities(Partner $partner): array { return array_column($this->createQueryBuilder('a')->select('DISTINCT a.city AS city')->where('a.partner = :partner')->andWhere('a.city IS NOT NULL')->setParameter('partner', $partner)->orderBy('a.city')->getQuery()->getArrayResult(), 'city'); }
    public function findDistinctStreets(Partner $partner, int $limit = 800): array { return array_column($this->createQueryBuilder('a')->select('DISTINCT a.street AS street')->where('a.partner = :partner')->andWhere('a.street IS NOT NULL')->setParameter('partner', $partner)->orderBy('a.street')->setMaxResults($limit)->getQuery()->getArrayResult(), 'street'); }
    public function countBySubtypeFiltered(Partner $partner, array $filters = [], int $limit = 10): array { $qb = $this->createQueryBuilder('a')->select('a.type AS type, a.subtype AS subtype, COUNT(a.id) AS total')->groupBy('a.type, a.subtype')->orderBy('total', 'DESC')->setMaxResults($limit); $this->applyFilters($qb, $partner, $filters); return $qb->getQuery()->getArrayResult(); }
    public function countByConfidenceFiltered(Partner $partner, array $filters = []): array { $qb = $this->createQueryBuilder('a')->select('a.confidence AS confidence, COUNT(a.id) AS total')->groupBy('a.confidence'); $this->applyFilters($qb, $partner, $filters); $out = ['0-3' => 0, '4-6' => 0, '7-10' => 0, 'Sem valor' => 0]; foreach ($qb->getQuery()->getArrayResult() as $r) { $c = $r['confidence']; $key = $c === null ? 'Sem valor' : ($c <= 3 ? '0-3' : ($c <= 6 ? '4-6' : '7-10')); $out[$key] += (int) $r['total']; } return $out; }
    public function countByDayFiltered(Partner $partner, array $filters = [], int $maxDays = 180): array { $qb = $this->createQueryBuilder('a')->select('a.pubMillis AS ts, COUNT(a.id) AS total')->groupBy('a.pubMillis')->orderBy('a.pubMillis', 'ASC')->setMaxResults($maxDays); $this->applyFilters($qb, $partner, $filters); $rows = $qb->getQuery()->getArrayResult(); $out = []; $tz = new \DateTimeZone('America/Sao_Paulo'); foreach ($rows as $r) { $day = (new \DateTimeImmutable('@' . intdiv((int) $r['ts'], 1000)))->setTimezone($tz)->format('Y-m-d'); $out[$day] = ($out[$day] ?? 0) + (int) $r['total']; } return array_map(static fn ($day, $total): array => ['day' => $day, 'total' => $total], array_keys($out), array_values($out)); }
    public function countByHourOfDayFiltered(Partner $partner, array $filters = []): array { $qb = $this->createQueryBuilder('a')->select('a.pubMillis AS ts, COUNT(a.id) AS total')->groupBy('a.pubMillis'); $this->applyFilters($qb, $partner, $filters); $out = array_fill(0, 24, 0); $tz = new \DateTimeZone('America/Sao_Paulo'); foreach ($qb->getQuery()->getArrayResult() as $r) { $hour = (int) (new \DateTimeImmutable('@' . intdiv((int) $r['ts'], 1000)))->setTimezone($tz)->format('G'); $out[$hour] += (int) $r['total']; } return $out; }
    public function countByWeekdayFiltered(Partner $partner, array $filters = []): array { $qb = $this->createQueryBuilder('a')->select('a.pubMillis AS ts, COUNT(a.id) AS total')->groupBy('a.pubMillis'); $this->applyFilters($qb, $partner, $filters); $out = array_fill(1, 7, 0); $tz = new \DateTimeZone('America/Sao_Paulo'); foreach ($qb->getQuery()->getArrayResult() as $r) { $w = (int) (new \DateTimeImmutable('@' . intdiv((int) $r['ts'], 1000)))->setTimezone($tz)->format('w'); $out[$w === 0 ? 1 : $w + 1] += (int) $r['total']; } return $out; }
    public function topStreetsFiltered(Partner $partner, array $filters = [], int $limit = 10): array { $qb = $this->createQueryBuilder('a')->select('a.street AS street, a.city AS city, COUNT(a.id) AS total')->andWhere('a.street IS NOT NULL')->groupBy('a.street, a.city')->orderBy('total', 'DESC')->setMaxResults($limit); $this->applyFilters($qb, $partner, $filters); return $qb->getQuery()->getArrayResult(); }
    public function findHotspotsFiltered(Partner $partner, array $filters = [], int $limit = 15): array { $qb = $this->createQueryBuilder('a')->select('a.latitude AS lat, a.longitude AS lng, COUNT(a.id) AS total', 'MAX(a.street) AS street', 'MAX(a.city) AS city')->andWhere('a.latitude IS NOT NULL')->andWhere('a.longitude IS NOT NULL')->groupBy('a.latitude, a.longitude')->orderBy('total', 'DESC')->setMaxResults($limit); $this->applyFilters($qb, $partner, $filters); return $qb->getQuery()->getArrayResult(); }

    public function findFiltered(int $hours, ?string $city, ?string $type, int $limit = 500): array
    {
        $since = (time() - max(1, $hours) * 3600) * 1000;
        $qb = $this->createQueryBuilder('a')
            ->where('a.pubMillis >= :since')
            ->setParameter('since', $since)
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit);
        if ($city) $qb->andWhere('LOWER(a.city) LIKE :city')->setParameter('city', '%' . mb_strtolower($city) . '%');
        if ($type) $qb->andWhere('a.type = :type')->setParameter('type', $type);
        return $qb->getQuery()->getResult();
    }
}
