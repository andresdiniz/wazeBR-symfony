<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CemadenData;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CemadenDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CemadenData::class);
    }

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->orderBy('c.accumulatedRain', 'DESC')
            ->getQuery()->getResult();
    }

    public function findByPartnerAndLevels(Partner $partner, array $levels): array
    {
        $since = new \DateTimeImmutable('-2 hours');
        return $this->createQueryBuilder('c')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->andWhere('c.alertLevel IN (:levels)')->setParameter('levels', $levels)
            ->andWhere('c.measuredAt >= :since')->setParameter('since', $since)
            ->orderBy('c.accumulatedRain', 'DESC')
            ->getQuery()->getResult();
    }

    public function findFilteredByPartner(
        Partner $partner,
        ?string $alertLevel = null,
        ?string $state      = null,
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->where('c.partner = :p')->setParameter('p', $partner)
            ->orderBy('c.accumulatedRain', 'DESC');

        if ($alertLevel) $qb->andWhere('c.alertLevel = :level')->setParameter('level', strtoupper($alertLevel));
        if ($state)      $qb->andWhere('c.state = :state')->setParameter('state', strtoupper($state));

        return $qb->getQuery()->getResult();
    }

    public function findOneByPartner(int $id, Partner $partner): ?CemadenData
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')->setParameter('id', $id)
            ->andWhere('c.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getOneOrNullResult();
    }

    public function save(CemadenData $data, bool $flush = true): void
    {
        $this->getEntityManager()->persist($data);
        if ($flush) $this->getEntityManager()->flush();
    }
}
