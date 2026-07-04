<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenHydroData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repositório para leituras hidrológicas CEMADEN.
 *
 * @extends ServiceEntityRepository<CemadenHydroData>
 */
class CemadenHydroDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenHydroData::class);
    }

    /**
     * Verifica se já existe um registro para esta estação e datahora.
     * Usa índice único (station_code, measured_at) para garantir idempotência.
     */
    public function existsByStationAndTime(string $stationCode, \DateTimeImmutable $measuredAt): bool
    {
        return (bool) $this->createQueryBuilder('h')
            ->select('1')
            ->where('h.stationCode = :code')
            ->andWhere('h.measuredAt = :at')
            ->setParameter('code', $stationCode)
            ->setParameter('at', $measuredAt)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retorna as últimas N leituras de uma estação, ordenadas por datahora DESC.
     *
     * @return CemadenHydroData[]
     */
    public function findLatestByStation(string $stationCode, int $limit = 24): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.stationCode = :code')
            ->setParameter('code', $stationCode)
            ->orderBy('h.measuredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna a leitura mais recente de cada estação (útil para o mapa).
     *
     * @return CemadenHydroData[]
     */
    public function findLatestPerStation(): array
    {
        // Subquery: MAX(measured_at) por station_code
        $sub = $this->createQueryBuilder('sub')
            ->select('MAX(sub.measuredAt)')
            ->where('sub.stationCode = h.stationCode')
            ->getDQL();

        return $this->createQueryBuilder('h')
            ->where("h.measuredAt = ($sub)")
            ->orderBy('h.stationCode', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
