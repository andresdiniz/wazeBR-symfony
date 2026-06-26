<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTvtSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTvtSnapshot>
 */
class WazeTvtSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtSnapshot::class);
    }

    public function findLatestByPartner(Partner $partner): ?WazeTvtSnapshot
    {
        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('s.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
