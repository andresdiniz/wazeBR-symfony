<?php

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

    public function findActiveByPartner(Partner $partner, int $minutes = 10): array
    {
        $since = (time() - (max(1, $minutes) * 60)) * 1000;

        return $this->createQueryBuilder('a')
            ->where('a.partner = :partner')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('a.pubMillis', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByPartner(int $id, Partner $partner): ?WazeAlert
    {
        return $this->createQueryBuilder('a')->where('a.id = :id')->andWhere('a.partner = :partner')->setParameter('id', $id)->setParameter('partner', $partner)->getQuery()->getOneOrNullResult();
    }
}
