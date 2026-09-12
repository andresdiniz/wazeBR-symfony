<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class WazeTvtRouteSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRouteSnapshot::class);
    }

    /**
     * @return WazeTvtRouteSnapshot[]
     */
    public function findRecentByRoute(
        Partner $partner,
        WazeTvtRoute $route,
        int $hours = 24,
        int $limit = 1000,
    ): array {
        $since = new \DateTimeImmutable(sprintf('-%d hours', $hours));

        return $this->createQueryBuilder('snapshot')
            ->andWhere('snapshot.partner = :partner')
            ->andWhere('snapshot.route = :route')
            ->andWhere('snapshot.recordedAt >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('route', $route)
            ->setParameter('since', $since)
            ->orderBy('snapshot.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
