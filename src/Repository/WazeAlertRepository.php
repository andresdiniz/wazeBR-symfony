<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, WazeAlert::class);
    }

    /**
     * Aplica todos os filtros usados nas telas, gráficos e exportação.
     */
    private function createFilteredQueryBuilder(object $partner, array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner);

        $type = $filters['type'] ?? null;
        $subtype = $filters['subtype'] ?? null;
        $city = $filters['city'] ?? null;
        $street = $filters['street'] ?? null;
        $excludeStreet = $filters['excludeStreet'] ?? null;
        $dateFrom = $filters['dateFrom'] ?? null;
        $dateTo = $filters['dateTo'] ?? null;

        if ($type !== null && $type !== '') {
            $qb->andWhere('a.type = :type')
                ->setParameter('type', $type);
        }

        if ($subtype !== null && $subtype !== '') {
            $qb->andWhere('a.subtype = :subtype')
                ->setParameter('subtype', $subtype);
        }

        if ($city !== null && $city !== '') {
            $qb->andWhere('a.city LIKE :city')
                ->setParameter('city', '%' . $city . '%');
        }

        if ($street !== null && $street !== '') {
            $qb->andWhere('a.street LIKE :street')
                ->setParameter('street', '%' . $street . '%');
        }

        if ($excludeStreet !== null && $excludeStreet !== '') {
            $qb->andWhere('(a.street IS NULL OR a.street NOT LIKE :excludeStreet)')
                ->setParameter('excludeStreet', '%' . $excludeStreet . '%');
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $qb->andWhere('a.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
        }

        if ($dateTo !== null && $dateTo !== '') {
            $qb->andWhere('a.createdAt <= :dateTo')
                ->setParameter('dateTo', $dateTo);
        }

        return $qb;
    }

    /**
     * Busca um alerta por ID, garantindo que ele pertença ao parceiro atual.
     */
    public function findOneByIdAndPartner(int $id, object $partner): ?WazeAlert
    {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->andWhere('a.partner = :partner')
            ->setParameter('id', $id)
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Mantido para compatibilidade, caso exista outra chamada antiga no projeto.
     */
    public function findOneByPartner(int $id, object $partner): ?WazeAlert
    {
        return $this->findOneByIdAndPartner($id, $partner);
    }

    /**
     * Conta alertas de um parceiro aplicando os filtros da tela.
     */
    public function countFilteredByPartner(object $partner, array $filters = []): int
    {
        return (int) $this->createFilteredQueryBuilder($partner, $filters)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retorna itens paginados e metadados de paginação.
     *
     * @return array{items: list<WazeAlert>, total: int, pages: int}
     */
    public function findFilteredByPartner(
        object $partner,
        array $filters = [],
        int $page = 1,
        int $limit = 30,
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);

        $total = $this->countFilteredByPartner($partner, $filters);

        $items = $this->createFilteredQueryBuilder($partner, $filters)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $limit)),
        ];
    }

    /**
     * Itera os alertas do export sem carregar todos os registros na memória.
     *
     * @return iterable<WazeAlert>
     */
    public function iterateFilteredByPartnerForExport(
        object $partner,
        array $filters = [],
        int $limit = 200000,
    ): iterable {
        $limit = max(1, $limit);

        $iterable = $this->createFilteredQueryBuilder($partner, $filters)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->toIterable();

        foreach ($iterable as $alert) {
            yield $alert;

            $this->getEntityManager()->detach($alert);
        }
    }

    /**
     * Mantido para chamadas que precisam de todos os alertas já materializados.
     *
     * @return list<WazeAlert>
     */
    public function findAllFilteredByPartner(
        object $partner,
        array $filters = [],
        ?int $limit = null,
    ): array {
        $qb = $this->createFilteredQueryBuilder($partner, $filters)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function countBySubtypeInPeriod(
        object $partner,
        mixed $startDate,
        mixed $endDate,
        int $limit = 10,
    ): array {
        return $this->createQueryBuilder('a')
            ->select('a.type, a.subtype, COUNT(a.id) AS total')
            ->where('a.partner = :partner')
            ->andWhere('a.createdAt >= :startDate')
            ->andWhere('a.createdAt <= :endDate')
            ->setParameter('partner', $partner)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->groupBy('a.type, a.subtype')
            ->orderBy('total', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getArrayResult();
    }

    public function countBySubtypeFiltered(
        object $partner,
        array $filters = [],
        int $limit = 10,
    ): array {
        return $this->createFilteredQueryBuilder($partner, $filters)
            ->select('a.type, a.subtype, COUNT(a.id) AS total')
            ->groupBy('a.type, a.subtype')
            ->orderBy('total', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Agrupamento realizado em PHP para não depender de funções DQL extras.
     */
    public function countByDayFiltered(object $partner, array $filters = []): array
    {
        $alerts = $this->createFilteredQueryBuilder($partner, $filters)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($alerts as $alert) {
            $createdAt = $alert->getCreatedAt();

            if ($createdAt === null) {
                continue;
            }

            $day = $createdAt->format('Y-m-d');
            $grouped[$day] = ($grouped[$day] ?? 0) + 1;
        }

        $result = [];

        foreach ($grouped as $day => $total) {
            $result[] = [
                'day' => $day,
                'total' => $total,
            ];
        }

        return $result;
    }

    /**
     * Retorna mapa numérico hora => total, formato esperado no AlertController.
     *
     * @return array<int, int>
     */
    public function countByHourOfDayFiltered(object $partner, array $filters = []): array
    {
        $alerts = $this->createFilteredQueryBuilder($partner, $filters)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $hours = array_fill(0, 24, 0);

        foreach ($alerts as $alert) {
            $createdAt = $alert->getCreatedAt();

            if ($createdAt === null) {
                continue;
            }

            $hour = (int) $createdAt->format('G');
            $hours[$hour]++;
        }

        return $hours;
    }

    /**
 * Retorna a distribuição de confiança no formato esperado pelo alert.js.
 *
 * As chaves são preservadas como níveis de confiança e os totais como inteiros.
 * Níveis sem ocorrências permanecem no resultado com valor zero.
 */
public function countByConfidenceFiltered(
    object $partner,
    array $filters = []
): array {
    $rows = $this->createFilteredQueryBuilder($partner, $filters)
        ->select('a.confidence, COUNT(a.id) AS total')
        ->andWhere('a.confidence IS NOT NULL')
        ->groupBy('a.confidence')
        ->orderBy('a.confidence', 'ASC')
        ->getQuery()
        ->getArrayResult();

    $result = [
        0 => 0,
        1 => 0,
        2 => 0,
        3 => 0,
        4 => 0,
        5 => 0,
    ];

    foreach ($rows as $row) {
        $confidence = (int) $row['confidence'];

        /*
         * Evita criar rótulos inesperados no doughnut quando houver dados
         * fora da faixa que a tela atual apresenta.
         */
        if (array_key_exists($confidence, $result)) {
            $result[$confidence] = (int) $row['total'];
        }
    }

    return $result;
}

    /**
 * Retorna totais por dia da semana no formato esperado pelo alert.js:
 *
 * 1 => Domingo
 * 2 => Segunda
 * 3 => Terça
 * 4 => Quarta
 * 5 => Quinta
 * 6 => Sexta
 * 7 => Sábado
 *
 * O agrupamento é feito em PHP para não depender de funções adicionais do DQL.
 */
public function countByWeekdayFiltered(
    object $partner,
    array $filters = []
): array {
    $alerts = $this->createFilteredQueryBuilder($partner, $filters)
        ->orderBy('a.createdAt', 'ASC')
        ->getQuery()
        ->getResult();

    $result = [
        1 => 0,
        2 => 0,
        3 => 0,
        4 => 0,
        5 => 0,
        6 => 0,
        7 => 0,
    ];

    foreach ($alerts as $alert) {
        $createdAt = $alert->getCreatedAt();

        if ($createdAt === null) {
            continue;
        }

        /*
         * PHP format('w'):
         * 0 = domingo, 1 = segunda, ..., 6 = sábado.
         *
         * O JavaScript da página usa:
         * 1 = domingo, 2 = segunda, ..., 7 = sábado.
         */
        $weekdayKey = (int) $createdAt->format('w') + 1;

        $result[$weekdayKey]++;
    }

    return $result;
}

    public function topStreetsFiltered(
        object $partner,
        array $filters = [],
        int $limit = 10,
    ): array {
        return $this->createFilteredQueryBuilder($partner, $filters)
            ->select('a.street, COUNT(a.id) AS total')
            ->andWhere('a.street IS NOT NULL')
            ->andWhere('a.street <> :emptyStreet')
            ->setParameter('emptyStreet', '')
            ->groupBy('a.street')
            ->orderBy('total', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getArrayResult();
    }

    public function findHotspotsFiltered(
        object $partner,
        array $filters = [],
        int $limit = 15,
    ): array {
        return $this->createFilteredQueryBuilder($partner, $filters)
            ->select('a.city, a.street, COUNT(a.id) AS total')
            ->groupBy('a.city, a.street')
            ->orderBy('total', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return list<WazeAlert>
     */
    public function findForMapFiltered(
        object $partner,
        array $filters = [],
        int $limit = 500,
    ): array {
        return $this->createFilteredQueryBuilder($partner, $filters)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    public function findDistinctTypes(object $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.type')
            ->where('a.partner = :partner')
            ->andWhere('a.type IS NOT NULL')
            ->setParameter('partner', $partner)
            ->orderBy('a.type', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function findDistinctSubtypes(object $partner, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT a.subtype')
            ->where('a.partner = :partner')
            ->andWhere('a.subtype IS NOT NULL')
            ->setParameter('partner', $partner);

        if ($type !== null && $type !== '') {
            $qb->andWhere('a.type = :type')
                ->setParameter('type', $type);
        }

        return $qb
            ->orderBy('a.subtype', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function findDistinctCities(object $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.city')
            ->where('a.partner = :partner')
            ->andWhere('a.city IS NOT NULL')
            ->setParameter('partner', $partner)
            ->orderBy('a.city', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function findDistinctStreets(object $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.street')
            ->where('a.partner = :partner')
            ->andWhere('a.street IS NOT NULL')
            ->setParameter('partner', $partner)
            ->orderBy('a.street', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * @return list<WazeAlert>
     */
    public function findActiveByPartner(object $partner, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
