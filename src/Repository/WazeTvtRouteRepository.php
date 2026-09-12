<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTvtRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class WazeTvtRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtRoute::class);
    }

    public function findOneByPartnerAndRouteId(
        Partner $partner,
        string $routeId,
    ): ?WazeTvtRoute {
        return $this->findOneBy([
            'partner' => $partner,
            'routeId' => $routeId,
        ]);
    }

    /**
     * @return WazeTvtRoute[]
     */
    public function findActiveByPartner(
        Partner $partner,
    ): array {
        return $this->createQueryBuilder('route')
            ->andWhere('route.partner = :partner')
            ->andWhere('route.isActive = :active')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->orderBy('route.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string[] $currentRouteIds
     */
    public function deactivateMissingForPartner(
        Partner $partner,
        array $currentRouteIds,
        \DateTimeImmutable $now,
    ): int {
        $currentRouteIds = array_values(array_unique(array_filter(
            $currentRouteIds,
            static fn (mixed $id): bool =>
                is_string($id) && trim($id) !== '',
        )));

        if ($currentRouteIds === []) {
            return 0;
        }

        return $this->createQueryBuilder('route')
            ->update()
            ->set('route.isActive', ':inactive')
            ->set('route.deactivatedAt', ':now')
            ->where('route.partner = :partner')
            ->andWhere('route.isActive = :active')
            ->andWhere('route.routeId NOT IN (:routeIds)')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->setParameter('inactive', false)
            ->setParameter('now', $now)
            ->setParameter('routeIds', $currentRouteIds)
            ->getQuery()
            ->execute();
    }
}
