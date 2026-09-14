<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenPluviometricObservation;
use App\Entity\CemadenStationLink;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CemadenPluviometricObservation>
 */
final class CemadenPluviometricObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenPluviometricObservation::class);
    }

    /**
     * @return CemadenPluviometricObservation[]
     */
    public function findLatestByStation(
        CemadenStationLink $station,
        int $limit = 24,
    ): array {
        return $this->createQueryBuilder('o')
            ->andWhere('o.cemadenStationLink = :station')
            ->setParameter('station', $station)
            ->orderBy('o.observedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CemadenPluviometricObservation[]
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
        CemadenStationLink $station,
        \DateTimeImmutable $observedAt,
    ): ?CemadenPluviometricObservation {
        return $this->findOneBy([
            'cemadenStationLink' => $station,
            'observedAt' => $observedAt,
        ]);
    }
}
