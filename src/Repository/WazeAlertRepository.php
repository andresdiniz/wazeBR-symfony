<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeAlert>
 */
class WazeAlertRepository extends ServiceEntityRepository
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function toMillis(\DateTimeInterface $dt): int
    {
        return (int) $dt->getTimestamp() * 1000;
    }

    /**
     * Aplica os filtros comuns da tela de histórico (/alertas) num QueryBuilder
     * já com alias 'a'. Todas as chaves são opcionais (null/vazio = sem filtro):
     *   type, subtype, city, street (LIKE, via a incluir),
     *   excludeStreet (termos separados por vírgula, vias a excluir via NOT LIKE),
     *   dateFrom, dateTo ('Y-m-d', interpretados em America/Sao_Paulo).
     */
    private function applyFilters(QueryBuilder $qb, Partner $partner, array $f): void
    {
        $qb->andWhere('a.partner = :partner')->setParameter('partner', $partner);

        if (!empty($f['type'])) {
            $qb->andWhere('a.type = :type')->setParameter('type', $f['type']);
        }
        if (!empty($f['subtype'])) {
            $qb->andWhere('a.subtype = :subtype')->setParameter('subtype', $f['subtype']);
        }
        if (!empty($f['city'])) {
            $qb->andWhere('LOWER(a.city) LIKE :city')->setParameter('city', '%' . mb_strtolower($f['city']) . '%');
        }
        if (!empty($f['street'])) {
            $qb->andWhere('LOWER(a.street) LIKE :street')->setParameter('street', '%' . mb_strtolower($f['street']) . '%');
        }
        if (!empty($f['excludeStreet'])) {
            foreach (self::splitTerms($f['excludeStreet']) as $i => $term) {
                $qb->andWhere("LOWER(a.street) NOT LIKE :exclStreet{$i}")
                   ->setParameter("exclStreet{$i}", '%' . mb_strtolower($term) . '%');
            }
        }

        $tz = new \DateTimeZone(self::TZ);
        if (!empty($f['dateFrom'])) {
            $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $f['dateFrom'], $tz);
            if ($from) {
                $qb->andWhere('a.pubMillis >= :dateFromMs')->setParameter('dateFromMs', self::toMillis($from));
            }
        }
        if (!empty($f['dateTo'])) {
            $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $f['dateTo'], $tz);
            if ($to) {
                $qb->andWhere('a.pubMillis <= :dateToMs')->setParameter('dateToMs', self::toMillis($to->setTime(23, 59, 59)));
            }
        }
    }

    /** @return string[] */
    private static function splitTerms(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn($t) => $t !== ''));
    }

    /**
     * Mesma lógica de applyFilters(), mas em SQL nativo (usado pelos métodos
     * que precisam de funções que o DQL não tem, como CONVERT_TZ/HOUR/DAYOFWEEK).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildNativeWhere(Partner $partner, array $f): array
    {
        $where = ['a.partner_id = :partnerId'];
        $params = ['partnerId' => $partner->getId()];

        if (!empty($f['type'])) {
            $where[] = 'a.type = :type';
            $params['type'] = $f['type'];
        }
        if (!empty($f['subtype'])) {
            $where[] = 'a.subtype = :subtype';
            $params['subtype'] = $f['subtype'];
        }
        if (!empty($f['city'])) {
            $where[] = 'LOWER(a.city) LIKE :city';
            $params['city'] = '%' . mb_strtolower($f['city']) . '%';
        }
        if (!empty($f['street'])) {
            $where[] = 'LOWER(a.street) LIKE :street';
            $params['street'] = '%' . mb_strtolower($f['street']) . '%';
        }
        if (!empty($f['excludeStreet'])) {
            foreach (self::splitTerms($f['excludeStreet']) as $i => $term) {
                $where[] = "LOWER(a.street) NOT LIKE :exclStreet{$i}";
                $params["exclStreet{$i}"] = '%' . mb_strtolower($term) . '%';
            }
        }

        $tz = new \DateTimeZone(self::TZ);
        if (!empty($f['dateFrom'])) {
            $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $f['dateFrom'], $tz);
            if ($from) {
                $where[] = 'a.pub_millis >= :dateFromMs';
                $params['dateFromMs'] = self::toMillis($from);
            }
        }
        if (!empty($f['dateTo'])) {
            $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $f['dateTo'], $tz);
            if ($to) {
                $where[] = 'a.pub_millis <= :dateToMs';
                $params['dateToMs'] = self::toMillis($to->setTime(23, 59, 59));
            }
        }

        return [implode(' AND ', $where), $params];
    }

    // ── Listagem paginada (página de histórico) ─────────────────────────────

    /**
     * @param array{type?:?string,subtype?:?string,city?:?string,street?:?string,excludeStreet?:?string,dateFrom?:?string,dateTo?:?string} $filters
     * @return array{items: WazeAlert[], total: int, pages: int}
     */
    public function findFilteredByPartner(Partner $partner, array $filters = [], int $page = 1, int $limit = 30): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $qb = $this->createQueryBuilder('a');
        $this->applyFilters($qb, $partner, $filters);

        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);

        $items = $qb->orderBy('a.pubMillis', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total, 'pages' => $pages];
    }

    /**
     * Alertas com coordenadas válidas do conjunto filtrado, para o mapa —
     * decoupled da paginação da tabela (senão o mapa só mostraria a página
     * atual). Limitado a $limit pontos, mais recentes primeiro.
     *
     * @param array $filters mesmo formato de findFilteredByPartner()
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

    // ── Opções de filtro ─────────────────────────────────────────────────────

    /** @return string[] */
    public function findDistinctTypes(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.type AS type')
            ->where('a.partner = :partner')->setParameter('partner', $partner)
            ->orderBy('a.type', 'ASC')
            ->getQuery()->getArrayResult();

        return array_column($rows, 'type');
    }

    /** @return string[] */
    public function findDistinctSubtypes(Partner $partner, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT a.subtype AS subtype')
            ->where('a.partner = :partner')->setParameter('partner', $partner)
            ->andWhere('a.subtype IS NOT NULL')
            ->orderBy('a.subtype', 'ASC');

        if ($type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        return array_column($qb->getQuery()->getArrayResult(), 'subtype');
    }

    /** @return string[] */
    public function findDistinctCities(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.city AS city')
            ->where('a.partner = :partner')->setParameter('partner', $partner)
            ->andWhere('a.city IS NOT NULL')
            ->orderBy('a.city', 'ASC')
            ->getQuery()->getArrayResult();

        return array_column($rows, 'city');
    }

    /** @return string[] — datalist de autocomplete pros campos de rua */
    public function findDistinctStreets(Partner $partner, int $limit = 800): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.street AS street')
            ->where('a.partner = :partner')->setParameter('partner', $partner)
            ->andWhere('a.street IS NOT NULL')
            ->orderBy('a.street', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_column($rows, 'street');
    }

    // ── Análises do conjunto filtrado (não só da página atual) ──────────────

    /** Distribuição por type+subtype. Retorna [['type','subtype','total'], ...] desc. */
    public function countBySubtypeFiltered(Partner $partner, array $filters = [], int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.type AS type, a.subtype AS subtype, COUNT(a.id) AS total')
            ->groupBy('a.type, a.subtype')
            ->orderBy('total', 'DESC')
            ->setMaxResults(max(1, $limit));
        $this->applyFilters($qb, $partner, $filters);

        return array_map(static fn(array $r) => [
            'type' => $r['type'], 'subtype' => $r['subtype'], 'total' => (int) $r['total'],
        ], $qb->getQuery()->getArrayResult());
    }

    /** Distribuição de confiança (0-3 / 4-6 / 7-10 / sem valor) do conjunto filtrado. */
    public function countByConfidenceFiltered(Partner $partner, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('a')->select('a.confidence AS confidence, COUNT(a.id) AS total')->groupBy('a.confidence');
        $this->applyFilters($qb, $partner, $filters);
        $rows = $qb->getQuery()->getArrayResult();

        $buckets = ['0-3' => 0, '4-6' => 0, '7-10' => 0, 'Sem valor' => 0];
        foreach ($rows as $r) {
            $c = $r['confidence'];
            $n = (int) $r['total'];
            if ($c === null) $buckets['Sem valor'] += $n;
            elseif ($c <= 3) $buckets['0-3'] += $n;
            elseif ($c <= 6) $buckets['4-6'] += $n;
            else $buckets['7-10'] += $n;
        }

        return $buckets;
    }

    /**
     * Ranking de vias no conjunto filtrado — quais mais aparecem. Serve tanto
     * como análise quanto pra guiar o preenchimento do filtro de exclusão.
     */
    public function topStreetsFiltered(Partner $partner, array $filters = [], int $limit = 12): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.street AS street', 'a.city AS city', 'COUNT(a.id) AS total')
            ->andWhere('a.street IS NOT NULL')
            ->groupBy('a.street, a.city')
            ->orderBy('total', 'DESC')
            ->setMaxResults(max(1, $limit));
        $this->applyFilters($qb, $partner, $filters);

        return array_map(static fn(array $r) => [
            'street' => $r['street'], 'city' => $r['city'], 'total' => (int) $r['total'],
        ], $qb->getQuery()->getArrayResult());
    }

    /**
     * Série diária (dia civil de Brasília) do conjunto filtrado — correlação
     * por data. Doctrine DQL não tem CONVERT_TZ, então usa SQL nativo aqui.
     * Retorna [['day' => 'Y-m-d', 'total' => n], ...] ordenado por dia.
     */
    public function countByDayFiltered(Partner $partner, array $filters = [], int $maxDays = 180): array
    {
        [$whereSql, $params] = $this->buildNativeWhere($partner, $filters);
        $params['maxDays'] = $maxDays;

        $sql = <<<SQL
            SELECT DATE(CONVERT_TZ(FROM_UNIXTIME(a.pub_millis / 1000), '+00:00', '-03:00')) AS day,
                   COUNT(a.id) AS total
            FROM waze_alerts a
            WHERE {$whereSql}
            GROUP BY day
            ORDER BY day ASC
            LIMIT :maxDays
        SQL;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);
    }

    /**
     * Distribuição por hora do dia (0-23, horário de Brasília) do conjunto
     * filtrado — correlação por data/horário: revela em que horas os alertas
     * se concentram (ex. rush da manhã vs. da tarde).
     * Sempre retorna as 24 chaves, mesmo com total 0, pra manter a escala do
     * gráfico estável entre filtros diferentes.
     */
    public function countByHourOfDayFiltered(Partner $partner, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildNativeWhere($partner, $filters);

        $sql = <<<SQL
            SELECT HOUR(CONVERT_TZ(FROM_UNIXTIME(a.pub_millis / 1000), '+00:00', '-03:00')) AS hour,
                   COUNT(a.id) AS total
            FROM waze_alerts a
            WHERE {$whereSql}
            GROUP BY hour
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);

        $byHour = array_fill(0, 24, 0);
        foreach ($rows as $r) {
            $h = (int) $r['hour'];
            if ($h >= 0 && $h <= 23) $byHour[$h] = (int) $r['total'];
        }

        return $byHour;
    }

    /**
     * Distribuição por dia da semana (1=domingo..7=sábado, horário de Brasília)
     * do conjunto filtrado — complementa a análise por hora.
     */
    public function countByWeekdayFiltered(Partner $partner, array $filters = []): array
    {
        [$whereSql, $params] = $this->buildNativeWhere($partner, $filters);

        $sql = <<<SQL
            SELECT DAYOFWEEK(CONVERT_TZ(FROM_UNIXTIME(a.pub_millis / 1000), '+00:00', '-03:00')) AS wd,
                   COUNT(a.id) AS total
            FROM waze_alerts a
            WHERE {$whereSql}
            GROUP BY wd
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);

        $byWeekday = array_fill(1, 7, 0);
        foreach ($rows as $r) {
            $wd = (int) $r['wd'];
            if ($wd >= 1 && $wd <= 7) $byWeekday[$wd] = (int) $r['total'];
        }

        return $byWeekday;
    }

    /**
     * Hotspots geográficos do conjunto filtrado — correlação por coordenadas.
     * Agrupa alertas numa grade de ~110m (3 casas decimais de lat/lng) em vez
     * de depender do texto da via, que às vezes vem nulo ou inconsistente
     * entre relatos do mesmo ponto físico. Retorna o centro médio de cada
     * célula com ocorrências, ordenado por contagem desc.
     */
    public function findHotspotsFiltered(Partner $partner, array $filters = [], int $limit = 15, int $minOccurrences = 3): array
    {
        [$whereSql, $params] = $this->buildNativeWhere($partner, $filters);
        $params['minOccurrences'] = $minOccurrences;
        $params['limit'] = $limit;

        $sql = <<<SQL
            SELECT
                ROUND(a.latitude, 3)  AS grid_lat,
                ROUND(a.longitude, 3) AS grid_lng,
                AVG(a.latitude)       AS lat,
                AVG(a.longitude)      AS lng,
                COUNT(a.id)           AS total,
                SUBSTRING_INDEX(GROUP_CONCAT(a.street ORDER BY a.id SEPARATOR '||'), '||', 1) AS sample_street,
                SUBSTRING_INDEX(GROUP_CONCAT(a.city   ORDER BY a.id SEPARATOR '||'), '||', 1) AS sample_city
            FROM waze_alerts a
            WHERE {$whereSql} AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL
              AND a.latitude != 0 AND a.longitude != 0
            GROUP BY grid_lat, grid_lng
            HAVING total >= :minOccurrences
            ORDER BY total DESC
            LIMIT :limit
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);

        return array_map(static fn(array $r) => [
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'total' => (int) $r['total'],
            'street' => $r['sample_street'],
            'city' => $r['sample_city'],
        ], $rows);
    }

    // ── Mapa ao vivo (/alertas/ao-vivo) ──────────────────────────────────────

    public function findLiveGroupedByRegion(Partner $partner, int $hours = 3): array
    {
        $since = (time() - $hours * 3600) * 1000;

        $rows = $this->createQueryBuilder('a')
            ->select('a.city AS city, COUNT(a.id) AS count')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->groupBy('a.city')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $r) => ['city' => $r['city'], 'count' => (int) $r['count']], $rows);
    }

    /** @return WazeAlert[] */
    public function findLiveByPartner(Partner $partner, int $hours = 3): array
    {
        $since = (time() - $hours * 3600) * 1000;

        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('a.pubMillis', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // ── Detalhe de um alerta ─────────────────────────────────────────────────

    public function findOneByPartner(int $id, Partner $partner): ?WazeAlert
    {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->andWhere('a.partner = :partner')
            ->setParameter('id', $id)
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // ── Usados pelo dashboard (não remover / não mudar assinatura) ──────────

    public function countInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBySubtypeInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to, int $limit = 12): array
    {
        $limit = max(1, $limit);

        $rows = $this->createQueryBuilder('a')
            ->select('a.type AS type, a.subtype AS subtype, COUNT(a.id) AS total')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->groupBy('a.type, a.subtype')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $r) => [
            'type' => $r['type'], 'subtype' => $r['subtype'], 'total' => (int) $r['total'],
        ], $rows);
    }

    public function findForMapInPeriod(Partner $partner, \DateTimeInterface $from, \DateTimeInterface $to, int $limit = 600): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.id, a.type, a.subtype, a.street, a.city, a.latitude, a.longitude, a.pubMillis')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', self::toMillis($from))
            ->setParameter('to', self::toMillis($to))
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    public function getAlertsPerHourLast24h(): array
    {
        $now = time() * 1000;
        $lastDay = $now - 24 * 3600 * 1000;

        $sql = <<<SQL
            SELECT
                CONCAT(DATE_FORMAT(FROM_UNIXTIME(a.pub_millis / 1000), '%H'), 'h') AS hour_label,
                COUNT(a.id) AS total
            FROM waze_alerts a
            WHERE a.pub_millis >= :lastDay
            GROUP BY hour_label
            ORDER BY hour_label ASC
        SQL;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, ['lastDay' => $lastDay]);
    }
}
