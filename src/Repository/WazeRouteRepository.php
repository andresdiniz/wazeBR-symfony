<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeRoute::class);
    }

    public function findByPartner(Partner $partner, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.partner = :p')->setParameter('p', $partner)
            ->orderBy('r.name', 'ASC');

        if ($activeOnly) {
            $qb->andWhere('r.isActive = true');
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByPartner(int $id, Partner $partner): ?WazeRoute
    {
        return $this->createQueryBuilder('r')
            ->where('r.id = :id')->setParameter('id', $id)
            ->andWhere('r.partner = :p')->setParameter('p', $partner)
            ->getQuery()->getOneOrNullResult();
    }

    public function save(WazeRoute $route, bool $flush = true): void
    {
        $this->getEntityManager()->persist($route);
        if ($flush) $this->getEntityManager()->flush();
    }
}
