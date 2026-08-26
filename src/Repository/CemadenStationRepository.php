<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenStation;
use App\Entity\Partner;
use App\Entity\StationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CemadenStation>
 */
class CemadenStationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenStation::class);
    }

    public function findActiveHydrologicalByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner')
            ->andWhere('s.stationType = :type')
            ->andWhere('s.isActive = true')
            ->andWhere('s.hydroUrl IS NOT NULL')
            ->andWhere('s.hydroUrl != \'\'')
            ->setParameter('partner', $partner)
            ->setParameter('type', StationType::HYDROLOGICAL)
            ->orderBy('s.nome', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveHydrologicalByIdAndPartner(int $id, Partner $partner): ?CemadenStation
    {
        return $this->createQueryBuilder('s')
            ->where('s.id = :id')
            ->andWhere('s.partner = :partner')
            ->andWhere('s.stationType = :type')
            ->andWhere('s.isActive = true')
            ->setParameter('id', $id)
            ->setParameter('partner', $partner)
            ->setParameter('type', StationType::HYDROLOGICAL)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
