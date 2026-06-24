<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeRoute;
use App\Entity\WazeRouteLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeRouteLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeRouteLink::class);
    }

    public function findByRoute(WazeRoute $route): array
    {
        return $this->createQueryBuilder('rl')
            ->where('rl.route = :route')
            ->setParameter('route', $route)
            ->orderBy('rl.sortOrder', 'ASC')
            ->getQuery()->getResult();
    }

    public function save(WazeRouteLink $link, bool $flush = true): void
    {
        $this->getEntityManager()->persist($link);
        if ($flush) $this->getEntityManager()->flush();
    }
}
