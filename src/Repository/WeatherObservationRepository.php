<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WeatherLocation;
use App\Entity\WeatherObservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WeatherObservation>
 */
class WeatherObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeatherObservation::class);
    }

    /**
     * Idempotência da coleta: uma observação por (location, observedAt).
     */
    public function findOneByLocationAndObservedAt(
        WeatherLocation $location,
        \DateTimeImmutable $observedAt,
    ): ?WeatherObservation {
        return $this->findOneBy([
            'weatherLocation' => $location,
            'observedAt'      => $observedAt,
        ]);
    }

    /**
     * Últimas N leituras de uma location.
     *
     * @return WeatherObservation[]
     */
    public function findLatestByLocation(
        WeatherLocation $location,
        int $limit = 24,
    ): array {
        return $this->createQueryBuilder('w')
            ->andWhere('w.weatherLocation = :location')
            ->setParameter('location', $location)
            ->orderBy('w.observedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Últimas N leituras de todas as locations de um partner.
     *
     * @return WeatherObservation[]
     */
    public function findLatestByPartner(
        Partner $partner,
        int $limit = 200,
    ): array {
        return $this->createQueryBuilder('w')
            ->addSelect('l')
            ->join('w.weatherLocation', 'l')
            ->andWhere('w.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('w.observedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Observações em um intervalo, ordenadas.
     *
     * @return WeatherObservation[]
     */
    public function findRangeByLocation(
        WeatherLocation $location,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->createQueryBuilder('w')
            ->andWhere('w.weatherLocation = :location')
            ->andWhere('w.observedAt >= :from')
            ->andWhere('w.observedAt <= :to')
            ->setParameter('location', $location)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('w.observedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Soma de chuva por hora (útil para gráficos de clima).
     *
     * @return list<array{hour:string,precipitation:float,rain:float}>
     */
    public function sumPrecipitationByHour(
        ?Partner $partner = null,
        int $hours = 24,
    ): array {
        $since = (new \DateTimeImmutable("-{$hours} hours", new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $qb = $this->createQueryBuilder('w')
            ->select(
                "DATE_FORMAT(w.observedAt, '%Y-%m-%d %H:00:00') AS hour",
                'SUM(w.precipitation) AS precipitation',
                'SUM(w.rain) AS rain',
            )
            ->andWhere('w.observedAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('hour')
            ->orderBy('hour', 'ASC');

        if ($partner !== null) {
            $qb
                ->andWhere('w.partner = :partner')
                ->setParameter('partner', $partner);
        }

        return $qb->getQuery()->getArrayResult();
    }
}
