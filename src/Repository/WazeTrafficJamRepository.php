<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeTrafficJam>
 */
class WazeTrafficJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeTrafficJam::class);
    }

    public function save(WazeTrafficJam $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Contagem total de jams do parceiro */
    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Jams mais recentes do parceiro (usados no dashboard) */
    public function findRecentByPartner(Partner $partner, int $limit = 10): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->orderBy('j.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Jams publicados nas últimas 2 horas (usados no mapa) */
    public function findActiveByPartner(Partner $partner): array
    {
        $since = (new \DateTimeImmutable('-2 hours'))->getTimestamp() * 1000;

        return $this->createQueryBuilder('j')
            ->where('j.partner = :p')
            ->setParameter('p', $partner)
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('since', $since)
            ->orderBy('j.pubMillis', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Alias de findRecentByPartner — mantido para retrocompatibilidade */
    public function findByPartnerRecent(Partner $partner, int $limit = 100): array
    {
        return $this->findRecentByPartner($partner, $limit);
    }

    public function countByLevelForPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('j')
            ->select('j.level, COUNT(j.id) as total')
            ->where('j.partner = :partner')
            ->setParameter('partner', $partner)
            ->groupBy('j.level')
            ->orderBy('j.level', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }
}
