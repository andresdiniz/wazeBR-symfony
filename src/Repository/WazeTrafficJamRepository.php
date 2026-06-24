<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeTrafficJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTrafficJam::class);
    }

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function findRecentByPartner(Partner $partner, int $limit = 5): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function findActiveByPartner(Partner $partner): array
    {
        $since = (new \DateTimeImmutable('-2 hours'))->getTimestamp() * 1000;

        return $this->createQueryBuilder('j')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->andWhere('j.pubMillis >= :since')->setParameter('since', $since)
            ->orderBy('j.level', 'DESC')
            ->getQuery()->getResult();
    }

    public function findFilteredByPartner(
        Partner  $partner,
        ?int     $minLevel = null,
        ?string  $city     = null,
        int      $page     = 1,
        int      $limit    = 30,
    ): array {
        $qb = $this->createQueryBuilder('j')
            ->where('j.partner = :p')->setParameter('p', $partner)
            ->orderBy('j.level', 'DESC')
            ->addOrderBy('j.pubMillis', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($minLevel !== null) {
            $qb->andWhere('j.level >= :level')->setParameter('level', $minLevel);
        }
        if ($city) {
            $qb->andWhere('j.city LIKE :city')->setParameter('city', "%{$city}%");
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByPartner(int $id, Partner $partner): ?WazeTrafficJam
    {
        return $this->createQueryBuilder('j')
            ->where('j.id = :id')->setParameter('id', $id)
            ->andWhere('j.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getOneOrNullResult();
    }

    public function save(WazeTrafficJam $jam, bool $flush = true): void
    {
        $this->getEntityManager()->persist($jam);
        if ($flush) $this->getEntityManager()->flush();
    }
}
