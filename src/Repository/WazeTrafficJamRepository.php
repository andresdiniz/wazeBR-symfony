<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTrafficJam>
 */
class WazeTrafficJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTrafficJam::class);
    }

    public function save(WazeTrafficJam $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByPartnerRecent(Partner $partner, int $limit = 100): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByLevelForPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('j')
            ->select('j.level, COUNT(j.id) as total')
            ->where('j.partner = :partner')
            ->setParameter('partner', $partner)
            ->groupBy('j.level')
            ->orderBy('j.level', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }
}
