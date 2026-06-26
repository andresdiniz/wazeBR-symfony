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

    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.partner = :partner')
            ->andWhere('c.isActive = true')
            ->setParameter('partner', $partner)
            ->orderBy('c.state', 'ASC')
            ->addOrderBy('c.name', 'ASC')   // campo correto: $name, nao $city
            ->getQuery()->getResult();
    }

    public function save(MonitoredCity $city, bool $flush = true): void
    {
        $this->getEntityManager()->persist($city);
        if ($flush) $this->getEntityManager()->flush();
    }
}
