<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function findRecentByPartner(Partner $partner, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.partner = :p')->setParameter('p', $partner)
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function findActiveByPartner(Partner $partner): array
    {
        $since = (new \DateTimeImmutable('-2 hours'))->getTimestamp() * 1000;

        return $this->createQueryBuilder('a')
            ->where('a.partner = :p')->setParameter('p', $partner)
            ->andWhere('a.pubMillis >= :since')->setParameter('since', $since)
            ->orderBy('a.pubMillis', 'DESC')
            ->getQuery()->getResult();
    }

    public function findFilteredByPartner(
        Partner  $partner,
        ?string  $type    = null,
        ?string  $city    = null,
        int      $page    = 1,
        int      $limit   = 30,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->where('a.partner = :p')->setParameter('p', $partner)
            ->orderBy('a.pubMillis', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }
        if ($city) {
            $qb->andWhere('a.city LIKE :city')->setParameter('city', "%{$city}%");
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByPartner(int $id, Partner $partner): ?WazeAlert
    {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')->setParameter('id', $id)
            ->andWhere('a.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getOneOrNullResult();
    }

    public function save(WazeAlert $alert, bool $flush = true): void
    {
        $this->getEntityManager()->persist($alert);
        if ($flush) $this->getEntityManager()->flush();
    }
}
