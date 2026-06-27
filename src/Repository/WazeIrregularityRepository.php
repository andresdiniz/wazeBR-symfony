<?php

namespace App\Repository;

use App\Entity\MonitoredLink;
use App\Entity\WazeIrregularity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeIrregularity>
 */
class WazeIrregularityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeIrregularity::class);
    }

    /**
     * Retorna todas as irregularidades ativas de um MonitoredLink.
     *
     * @return WazeIrregularity[]
     */
    public function findActiveByLink(MonitoredLink $link): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.sourceLink = :link')
            ->andWhere('i.isActive = true')
            ->setParameter('link', $link)
            ->orderBy('i.jamLevel', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
