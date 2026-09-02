<?php

namespace App\Repository;

use App\Entity\WazeTvtRouteExecutionCoord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WazeTvtRouteExecutionCoordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRouteExecutionCoord::class);
    }

    /**
     * @return WazeTvtRouteExecutionCoord[]
     */
    public function findByExecutionId(int $executionId): array
    {
        return $this->findBy(['execution' => $executionId], ['position' => 'ASC']);
    }
}
