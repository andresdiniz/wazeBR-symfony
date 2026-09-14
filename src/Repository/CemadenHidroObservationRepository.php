<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenHidroObservation;
use App\Entity\CemadenHidroStationLink;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CemadenHidroObservation>
 */
final class CemadenHidroObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenHidroObservation::class);
    }

    /**
     * @return CemadenHidroObservation[]
     */
    public function findLatestByStation(
        CemadenHidroStationLink $station,
        int $limit = 24,
    ): array {
        return $this->createQueryBuilder('o')
            ->andWhere('o.cemadenHidroStationLink = :station')
            ->setParameter('station', $station)
            ->orderBy('o.observedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CemadenHidroObservation[]
     */
    public function findLatestByPartner(
        Partner $partner,
        int $limit = 500,
    ): array {
        return $this->createQueryBuilder('o')
            ->andWhere('o.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('o.observedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneByStationAndObservedAt(
        CemadenHidroStationLink $station,
        \DateTimeImmutable $observedAt,
    ): ?CemadenHidroObservation {
        return $this->findOneBy([
            'cemadenHidroStationLink' => $station,
            'observedAt' => $observedAt,
        ]);
    }
}
