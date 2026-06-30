<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredCity;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MonitoredCityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoredCity::class);
    }

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.partner = :p')
            ->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.partner = :partner')
            ->andWhere('c.isActive = true')
            ->setParameter('partner', $partner)
            ->orderBy('c.state', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()->getResult();
    }

    public function save(MonitoredCity $city, bool $flush = true): void
    {
        $this->getEntityManager()->persist($city);
        if ($flush) $this->getEntityManager()->flush();
    }
}
