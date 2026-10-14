<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTvtRouteForecast;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTvtRouteForecast>
 */
final class WazeTvtRouteForecastRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRouteForecast::class);
    }

    /**
     * Retorna a previsão mais próxima de cada rota dentro da janela informada.
     *
     * @param int[] $routeIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function findUpcomingGroupedByRoute(
        array $routeIds,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        if ($routeIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('f')
            ->select(
                'IDENTITY(f.route) AS routeId',
                'f.forecastAt AS forecastAt',
                'f.slotMinutes AS slotMinutes',
                'f.avgTime AS avgTime',
                'f.avgHistoricTime AS avgHistoricTime',
                'f.avgJamLevel AS avgJamLevel',
                'f.samples AS samples',
                'f.riskScore AS riskScore',
                'f.riskLevel AS riskLevel',
            )
            ->where('IDENTITY(f.route) IN (:routeIds)')
            ->andWhere('f.forecastAt >= :from')
            ->andWhere('f.forecastAt < :to')
            ->setParameter('routeIds', $routeIds)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('f.forecastAt', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $grouped = [];

        foreach ($rows as $row) {
            $routeId = (int) $row['routeId'];

            if (!isset($grouped[$routeId])) {
                $grouped[$routeId] = $row;
            }
        }

        return $grouped;
    }

    /**
     * Rotas com risco alto/crítico para uma janela futura.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findHighRiskForWindow(
        ?Partner $partner,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $minimumSamples = 3,
    ): array {
        $qb = $this->createQueryBuilder('f')
            ->select(
                'f.id',
                'IDENTITY(f.route) AS routeId',
                'r.name AS routeName',
                'r.fromName AS fromName',
                'r.toName AS toName',
                'f.forecastAt AS forecastAt',
                'f.riskScore AS riskScore',
                'f.riskLevel AS riskLevel',
                'f.avgTime AS avgTime',
                'f.avgHistoricTime AS avgHistoricTime',
                'f.samples AS samples',
            )
            ->innerJoin('f.route', 'r')
            ->where('f.forecastAt >= :from')
            ->andWhere('f.forecastAt < :to')
            ->andWhere('f.riskLevel IN (:levels)')
            ->andWhere('f.samples >= :samples')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('levels', [
                WazeTvtRouteForecast::LEVEL_HIGH,
                WazeTvtRouteForecast::LEVEL_CRITICAL,
            ])
            ->setParameter('samples', $minimumSamples)
            ->orderBy('f.riskScore', 'DESC');

        if ($partner !== null) {
            $qb
                ->andWhere('r.partner = :partner')
                ->setParameter('partner', $partner);
        }

        return $qb->getQuery()->getArrayResult();
    }
}
