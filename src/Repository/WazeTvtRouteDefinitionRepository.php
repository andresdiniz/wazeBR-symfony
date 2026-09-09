<?php

namespace App\Repository;

use App\Entity\WazeTvtRouteDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeTvtRouteDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRouteDefinition::class);
    }

    public function findOneByRouteAndHash(int $routeId, string $definitionHash): ?WazeTvtRouteDefinition
    {
        return $this->createQueryBuilder('d')
            ->join('d.wazeTvtRoute', 'r')
            ->where('r.id = :routeId')
            ->andWhere('d.definitionHash = :hash')
            ->setParameter('routeId', $routeId)
            ->setParameter('hash', $definitionHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findCurrentByRoute(int $routeId): ?WazeTvtRouteDefinition
    {
        return $this->createQueryBuilder('d')
            ->join('d.wazeTvtRoute', 'r')
            ->where('r.id = :routeId')
            ->andWhere('d.isCurrent = :current')
            ->setParameter('routeId', $routeId)
            ->setParameter('current', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getNextVersionNumber(int $routeId): int
    {
        $result = $this->createQueryBuilder('d')
            ->select('MAX(d.versionNumber)')
            ->join('d.wazeTvtRoute', 'r')
            ->where('r.id = :routeId')
            ->setParameter('routeId', $routeId)
            ->getQuery()
            ->getSingleScalarResult();

        return ($result ?? 0) + 1;
    }
}
