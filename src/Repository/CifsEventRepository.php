<?php

namespace App\Repository;

use App\Entity\CifsEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CifsEvent>
 */
class CifsEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CifsEvent::class);
    }

    /**
     * Retorna eventos ativos que ainda não expiraram (para o feed do Waze).
     *
     * @return CifsEvent[]
     */
    public function findActiveForFeed(): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('e')
            ->where('e.active = true')
            ->andWhere('e.startTime <= :now')
            ->andWhere('e.endTime IS NULL OR e.endTime > :now')
            ->setParameter('now', $now)
            ->orderBy('e.creationTime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Listagem para o painel administrativo com filtros.
     *
     * @return CifsEvent[]
     */
    public function findFiltered(
        bool $onlyActive = false,
        ?string $type = null,
        int $limit = 100
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.partner', 'p')
            ->addSelect('p')
            ->orderBy('e.creationTime', 'DESC')
            ->setMaxResults($limit);

        if ($onlyActive) {
            $qb->andWhere('e.active = true');
        }
        if ($type) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }
}
