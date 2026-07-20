<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeCount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeCount>
 */
class WazeCountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeCount::class);
    }

    /**
     * Snapshot mais recente de usersOnJams (sem filtro por parceiro —
     * a tabela waze_counts é global por coleta).
     *
     * Retorna null quando ainda não há nenhuma coleta registrada.
     */
    public function findLatest(): ?WazeCount
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Série histórica dos últimos N snapshots (do mais recente para o mais antigo).
     */
    public function findRecent(int $limit = 48): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.collectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
