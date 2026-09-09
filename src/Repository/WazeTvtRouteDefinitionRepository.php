<?php

namespace App\Repository;

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

    public function findOneByRouteId(string $routeId): ?WazeTvtRouteDefinition
    {
        return $this->findOneBy(['routeId' => $routeId]);
    }

    //    /**
    //     * @return WazeTvtRouteDefinition[] Returns an array of WazeTvtRouteDefinition objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('w')
    //            ->andWhere('w.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('w.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function save(WazeTvtRouteDefinition $entity): void
    //    {
    //        $this->getEntityManager()->persist($entity);
    //        $this->getEntityManager()->flush();
    //    }

    //    public function remove(WazeTvtRouteDefinition $entity): void
    //    {
    //        $this->getEntityManager()->remove($entity);
    //        $this->getEntityManager()->flush();
    //    }
}
