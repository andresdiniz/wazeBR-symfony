<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTvtIrregularity;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtSubRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class WazeTvtIrregularityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtIrregularity::class);
    }

    public function findOneByContentHash(
        Partner $partner,
        WazeTvtRoute $route,
        ?WazeTvtSubRoute $subRoute,
        string $contentHash,
    ): ?WazeTvtIrregularity {
        return $this->findOneBy([
            'partner' => $partner,
            'route' => $route,
            'subRoute' => $subRoute,
            'contentHash' => $contentHash,
        ]);
    }

    /**
     * @param string[] $currentHashes
     */
    public function deactivateMissingForScope(
        Partner $partner,
        WazeTvtRoute $route,
        ?WazeTvtSubRoute $subRoute,
        array $currentHashes,
        \DateTimeImmutable $now,
    ): int {
        $currentHashes = array_values(array_unique(array_filter(
            $currentHashes,
            static fn (mixed $hash): bool =>
                is_string($hash) && trim($hash) !== '',
        )));

        if ($currentHashes === []) {
            return 0;
        }

        $queryBuilder = $this->createQueryBuilder('irregularity')
            ->update()
            ->set('irregularity.isActive', ':inactive')
            ->set('irregularity.deactivatedAt', ':now')
            ->where('irregularity.partner = :partner')
            ->andWhere('irregularity.route = :route')
            ->andWhere('irregularity.isActive = :active')
            ->andWhere('irregularity.contentHash NOT IN (:hashes)')
            ->setParameter('partner', $partner)
            ->setParameter('route', $route)
            ->setParameter('active', true)
            ->setParameter('inactive', false)
            ->setParameter('now', $now)
            ->setParameter('hashes', $currentHashes);

        if ($subRoute === null) {
            $queryBuilder->andWhere('irregularity.subRoute IS NULL');
        } else {
            $queryBuilder
                ->andWhere('irregularity.subRoute = :subRoute')
                ->setParameter('subRoute', $subRoute);
        }

        return $queryBuilder
            ->getQuery()
            ->execute();
    }
}
