<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTvtUserOnJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class WazeTvtUserOnJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTvtUserOnJam::class);
    }

    /**
     * @return WazeTvtUserOnJam[]
     */
    public function findRecentByPartner(
        Partner $partner,
        int $hours = 24,
        int $limit = 1000,
    ): array {
        $since = new \DateTimeImmutable(sprintf('-%d hours', $hours));

        return $this->createQueryBuilder('userOnJam')
            ->andWhere('userOnJam.partner = :partner')
            ->andWhere('userOnJam.recordedAt >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $since)
            ->orderBy('userOnJam.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
