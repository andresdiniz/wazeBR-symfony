<?php

namespace App\Repository;

use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTvtRouteDefinition>
 */
class WazeTvtRouteDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRouteDefinition::class);
    }

    public function findOneByRouteAndCurrent(WazeTvtRoute $route, bool $isCurrent): ?WazeTvtRouteDefinition
    {
        return $this->findOneBy([
            'wazeTvtRoute' => $route,
            'isCurrent' => $isCurrent,
        ]);
    }
}
