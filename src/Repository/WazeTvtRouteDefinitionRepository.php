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

    public function findOneByRouteId(string $routeId): ?WazeTvtRouteDefinition
    {
        return $this->findOneBy(['routeId' => $routeId]);
    }
}
