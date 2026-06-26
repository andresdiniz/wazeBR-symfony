<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeRoute>
 */
class WazeRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly EntityManagerInterface $em)
    {
        parent::__construct($registry, WazeRoute::class);
    }

    /**
     * @return WazeRoute[]
     */
    public function findByPartner(Partner $partner, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('r.name', 'ASC');

        if ($activeOnly) {
            $qb->andWhere('r.isActive = true');
        }

        return $qb->getQuery()->getResult();
    }

    public function countByPartner(Partner $partner, bool $activeOnly = true): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.partner = :partner')
            ->setParameter('partner', $partner);

        if ($activeOnly) {
            $qb->andWhere('r.isActive = true');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findOneByPartner(int $id, Partner $partner): ?WazeRoute
    {
        return $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->andWhere('r.partner = :partner')
            ->setParameter('id', $id)
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(WazeRoute $route): void
    {
        $this->em->persist($route);
        $this->em->flush();
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }
}
