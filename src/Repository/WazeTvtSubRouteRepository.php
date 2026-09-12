<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtSubRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class WazeTvtSubRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtSubRoute::class);
    }

    public function findOneByIdentity(
        Partner $partner,
        WazeTvtRoute $route,
        string $subRouteId,
    ): ?WazeTvtSubRoute {
        return $this->findOneBy([
            'partner' => $partner,
            'route' => $route,
            'subRouteId' => $subRouteId,
        ]);
    }

    /**
     * @param string[] $currentSubRouteIds
     */
    public function deactivateMissingForRoute(
        Partner $partner,
        WazeTvtRoute $route,
        array $currentSubRouteIds,
        \DateTimeImmutable $now,
    ): int {
        $currentSubRouteIds = array_values(array_unique(array_filter(
            $currentSubRouteIds,
            static fn (mixed $id): bool =>
                is_string($id) && trim($id) !== '',
        )));

        if ($currentSubRouteIds === []) {
            return 0;
        }

        return $this->createQueryBuilder('subRoute')
            ->update()
            ->set('subRoute.isActive', ':inactive')
            ->set('subRoute.deactivatedAt', ':now')
            ->where('subRoute.partner = :partner')
            ->andWhere('subRoute.route = :route')
            ->andWhere('subRoute.isActive = :active')
            ->andWhere('subRoute.subRouteId NOT IN (:ids)')
            ->setParameter('partner', $partner)
            ->setParameter('route', $route)
            ->setParameter('active', true)
            ->setParameter('inactive', false)
            ->setParameter('now', $now)
            ->setParameter('ids', $currentSubRouteIds)
            ->getQuery()
            ->execute();
    }
}
