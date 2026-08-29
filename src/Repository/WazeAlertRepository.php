<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    /**
     * Query base para todos os filtros da tela de alertas.
     *
     * @param array{
     *     type?: string|null,
     *     subtype?: string|null,
     *     city?: string|null,
     *     street?: string|null,
     *     excludeStreet?: string|null,
     *     dateFrom?: string|\DateTimeInterface|null,
     *     dateTo?: string|\DateTimeInterface|null
     * } $filters
     */
    private function createFilteredQueryBuilder(
        Partner $partner,
        array $filters = [],
    ): QueryBuilder {
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

        /*
         * `excludeStreet` pode ter uma ou mais vias separadas por vírgula.
         * A lógica inclui alertas sem nome de via e exclui qualquer via cujo
         * texto contenha um dos valores informados.
         */
        if ($excludeStreet !== null && trim($excludeStreet) !== '') {
            $excludedStreets = array_filter(
                array_map(
                    static fn (string $value): string => trim($value),
                    explode(',', $excludeStreet),
                ),
                static fn (string $value): bool => $value !== '',
            );

            foreach (array_values($excludedStreets) as $index => $excludedStreet) {
                $parameter = 'excludeStreet_' . $index;

                $qb->andWhere(sprintf(
                    '(a.street IS NULL OR a.street NOT LIKE :%s)',
                    $parameter,
                ))
                    ->setParameter($parameter, '%' . $excludedStreet . '%');
            }
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
     * Busca um alerta por ID e garante que ele pertence ao parceiro atual.
     */
    public function findOneByIdAndPartner(
        int $id,
        Partner $partner,
    ): ?WazeAlert {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->andWhere('a.partner = :partner')
            ->setParameter('id', $id)
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compatibilidade com chamadas existentes no AlertController.
     *
     * O primeiro parâ��metro é o ID do alerta, não o ID do parceiro.
     */
    public function findOneByPartner(
        int $id,
        Partner $partner,
    ): ?WazeAlert {
        return $this->findOneByIdAndPartner($id, $partner);
    }

    /**
     * Conta os alertas de um parceiro aplicando os filtros informados.
     */
    public function countFilteredByPartner(
        Partner $partner,
        array $filters = [],
    ): int {
        return (int) $this->createFilteredQueryBuilder($partner, $filters)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Busca alertas com paginaçº£o.
     *
     * @return array{
     *     items: list<WazeAlert>,
     *     total: int,
     *     pages: int
     * }
     */
    public function findFilteredByPartner(
        Partner $partner,
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
     * Exporta alertas em modo iterå¡¶el, reduzindo uso de memå¡¶ria.
     *
     * @return iterable<WazeAlert>
     */
    public function iterateFilteredByPartnerForExport(
        Partner $partner,
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

            /*
             * Evita crescer o UnitOfWork durante a geraçº£o de CSV grande.
             */
            $this->getEntityManager()->detach($alert);
        }
    }

    /**
     * Retorna todos os alertas filtrados, opcionalmente limitados.
     *
     * @return list<WazeAlert>
     */
    public function findAllFilteredByPartner(
        Partner $partner,
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

    /**
     * Distribuiçº£o de alertas por tipo/subtipo em um perå¡¶odo.
     *
     * @return list<array{type: string|null, subtype: string|null, total: string|int}>
     */
    public function countBySubtypeInPeriod(
        Partner $partner,
        \DateTimeInterface|string $startDate,
        \DateTimeInterface|string $endDate,
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

    /**
     * Distribuiçº£o de alertas por subtipo com os filtros atuais.
     */
    public function countBySubtypeFiltered(
        Partner $partner,
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
     * Grå¡¶fico por dia.
     *
     * O agrupamento é feito em PHP porque o projeto nå££o tem as funçµµes
     * DATE/YEAR/MONTH registradas para DQL.
     *
     * @return list<array{day: string, total: int}>
     */
    public function countByDayFiltered(
        Partner $partner,
        array $filters = [],
    ): array {
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
     * Grå¡¶fico por hora.
     *
     * O `public/js/alert.js` espera uma lista indexada de 0 a 23.
     *
     * @return list<int>
     */
    public function countByHourOfDayFiltered(
        Partner $partner,
        array $filters = [],
    ): array {
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

        return array_values($hours);
    }

    /**
     * Grå¡¶fico de confiança.
     *
     * O JavaScript espera um objeto no formato:
     * { "0": 0, "1": 2, "2": 4, ... }.
     *
     * @return array<int, int>
     */
    public function countByConfidenceFiltered(
        Partner $partner,
        array $filters = [],
    ): array {
        $rows = $this->createFilteredQueryBuilder($partner, $filters)
            ->select('a.confidence, COUNT(a.id) AS total')
            ->andWhere('a.confidence IS NOT NULL')
            ->groupBy('a.confidence')
            ->orderBy('a.confidence', 'ASC')
            ->getQuery()
            ->getArrayResult();

        /*
         * Manté¡µ as faixas atuais exibidas no grå¡¶fico.
         * Valores fora de 0..5 säo preservados abaixo, se existirem.
         */
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
            $result[$confidence] = (int) $row['total'];
        }

        ksort($result);

        return $result;
    }

    /**
     * Grå¡¶fico por dia da semana.
     *
     * O JavaScript usa 1=Domingo, 2=Segunda, ..., 7=Så¡¶bado.
     *
     * @return array<int, int>
     */
    public function countByWeekdayFiltered(
        Partner $partner,
        array $filters = [],
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
             * format('w'): 0=domingo, 1=segunda, ..., 6=så¡¶bado.
             * O front usa 1=domingo até 7=så¡¶bado.
             */
            $weekdayKey = (int) $createdAt->format('w') + 1;
            $result[$weekdayKey]++;
        }

        return $result;
    }

    /**
     * Principais vias por quantidade de alertas.
     */
    public function topStreetsFiltered(
        Partner $partner,
        array $filters = [],
        int $limit = 10,
    ): array {
        return $this->createFilteredQueryBuilder($partner, $filters)
            ->select('a.street, a.city, COUNT(a.id) AS total')
            ->andWhere('a.street IS NOT NULL')
            ->andWhere('a.street <> :emptyStreet')
            ->setParameter('emptyStreet', '')
            ->groupBy('a.street, a.city')
            ->orderBy('total', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Hotspots textuais por cidade e via.
     *
     * @return list<array{city: string|null, street: string|null, total: string|int}>
     */
    public function findHotspotsFiltered(
        Partner $partner,
        array $filters = [],
        int $limit = 15,
    ): array {
        return $this->createFilteredQueryBuilder($partner, $filters)
            ->select('a.city, a.street, COUNT(a.id) AS total')
            ->groupBy('a.city, a.street')
            ->having('COUNT(a.id) >= 3')
            ->orderBy('total', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Alertas apresentados no mapa.
     *
     * @return list<WazeAlert>
     */
    public function findForMapFiltered(
        Partner $partner,
        array $filters = [],
        int $limit = 500,
    ): array {
        return $this->createFilteredQueryBuilder($partner, $filters)
            ->andWhere('a.latitude IS NOT NULL')
            ->andWhere('a.longitude IS NOT NULL')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<string>
     */
    public function findDistinctTypes(Partner $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.type')
            ->where('a.partner = :partner')
            ->andWhere('a.type IS NOT NULL')
            ->andWhere('a.type <> :emptyType')
            ->setParameter('partner', $partner)
            ->setParameter('emptyType', '')
            ->orderBy('a.type', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * @return list<string>
     */
    public function findDistinctSubtypes(
        Partner $partner,
        ?string $type = null,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->select('DISTINCT a.subtype')
            ->where('a.partner = :partner')
            ->andWhere('a.subtype IS NOT NULL')
            ->andWhere('a.subtype <> :emptySubtype')
            ->setParameter('partner', $partner)
            ->setParameter('emptySubtype', '');

        if ($type !== null && $type !== '') {
            $qb->andWhere('a.type = :type')
                ->setParameter('type', $type);
        }

        return $qb
            ->orderBy('a.subtype', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * @return list<string>
     */
    public function findDistinctCities(Partner $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.city')
            ->where('a.partner = :partner')
            ->andWhere('a.city IS NOT NULL')
            ->andWhere('a.city <> :emptyCity')
            ->setParameter('partner', $partner)
            ->setParameter('emptyCity', '')
            ->orderBy('a.city', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * @return list<string>
     */
    public function findDistinctStreets(Partner $partner): array
    {
        return $this->createQueryBuilder('a')
            ->select('DISTINCT a.street')
            ->where('a.partner = :partner')
            ->andWhere('a.street IS NOT NULL')
            ->andWhere('a.street <> :emptyStreet')
            ->setParameter('partner', $partner)
            ->setParameter('emptyStreet', '')
            ->orderBy('a.street', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Alertas recentes para a pÅ¡gina ao vivo.
     *
     * @return list<WazeAlert>
     */
    public function findActiveByPartner(
        Partner $partner,
        int $limit = 10,
    ): array {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Alertas crå¡¶ticos recentes para o job notifications:dispatch.
     *
     * Assinatura compatå¡¶vel com o command:
     *
     *     findCriticalByPartner($partner, $since, $limit)
     *
     * @return list<WazeAlert>
     */
    public function findCriticalByPartner(
        Partner $partner,
        ?\DateTimeInterface $since = null,
        int $limit = 100,
    ): array {
        $since ??= new \DateTimeImmutable(
            '-30 minutes',
            new \DateTimeZone('UTC'),
        );

        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->andWhere('a.createdAt >= :since')
            ->andWhere(
                'a.type IN (:criticalTypes)'
            )
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->setParameter('criticalTypes', [
                'ACCIDENT',
                'HAZARD',
                'WEATHERHAZARD',
                'JAM',
            ])
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Alertas de alto risco para o job notify_high_risk.
     *
     * Manté¡µ compatibilidade com chamadas simples:
     *
     *     $repository->findHighRiskAlerts()
     *
     * e com chamadas filtradas por parceiro:
     *
     *     $repository->findHighRiskAlerts($partner)
     *
     * @return list<WazeAlert>
     */
    public function findHighRiskAlerts(
        ?Partner $partner = null,
        ?\DateTimeInterface $since = null,
        int $minReliability = 8,
        int $limit = 100,
    ): array {
        $since ??= new \DateTimeImmutable(
            '-30 minutes',
            new \DateTimeZone('UTC'),
        );

        $qb = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :since')
            ->andWhere('a.reliability >= :minReliability')
            ->andWhere('a.type IN (:highRiskTypes)')
            ->setParameter('since', $since)
            ->setParameter('minReliability', max(0, $minReliability))
            ->setParameter('highRiskTypes', [
                'ACCIDENT',
                'HAZARD',
                'WEATHERHAZARD',
                'JAM',
            ])
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($partner !== null) {
            $qb->andWhere('a.partner = :partner')
                ->setParameter('partner', $partner);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta alertas de um parceiro em uma data especå¡¶fica (UTC).
     */
    public function countByDate(Partner $partner, \DateTimeInterface $date): int
    {
        $start = new \DateTimeImmutable(
            $date->format('Y-m-d') . ' 00:00:00',
            new \DateTimeZone('UTC'),
        );

        $end = new \DateTimeImmutable(
            $date->format('Y-m-d') . ' 23:59:59',
            new \DateTimeZone('UTC'),
        );

        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :partner')
            ->andWhere('a.createdAt >= :start')
            ->andWhere('a.createdAt <= :end')
            ->setParameter('partner', $partner)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }
}