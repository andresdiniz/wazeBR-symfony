<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
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
     * Leitura mais recente de contagens para o partner.
     */
    public function findLatest(Partner $partner): ?WazeCount
    {
        return $this->createQueryBuilder('c')
            ->where('c.<NOME_DO_CAMPO> = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('c.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Últimas $limit leituras para sparklines (mais recente primeiro).
     */
    public function findRecent(Partner $partner, int $limit = 24): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('c.collectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Pico do dia: máximo de cada indicador com o horário em que ocorreu.
     * Retorna ['max_jams'=>47,'max_jams_at'=>'08:15','max_alerts'=>23,...]
     */
    public function peakOfDay(Partner $partner): array
    {
        $since = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');

        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT
                MAX(total_jams)           AS max_jams,
                MAX(total_alerts)         AS max_alerts,
                MAX(total_irregularities) AS max_irreg,
                MAX(total_mentions)       AS max_mentions
            FROM waze_counts
            WHERE partner_id = :partner
              AND collected_at >= :since
        ';
        $stmt   = $conn->prepare($sql);
        $result = $stmt->executeQuery(['partner' => $partner->getId(), 'since' => $since]);
        $row    = $result->fetchAssociative();

        return [
            'max_jams'     => (int)($row['max_jams'] ?? 0),
            'max_alerts'   => (int)($row['max_alerts'] ?? 0),
            'max_irreg'    => (int)($row['max_irreg'] ?? 0),
            'max_mentions' => (int)($row['max_mentions'] ?? 0),
        ];
    }

    /**
     * Comparativo semana passada: mesmo horário 7 dias atrás.
     * Retorna a WazeCount mais próxima ao horário atual de 7 dias atrás.
     */
    public function findSameTimeLastWeek(Partner $partner): ?WazeCount
    {
        $target     = new \DateTimeImmutable('-7 days');
        $windowFrom = (clone $target)->modify('-30 minutes');
        $windowTo   = (clone $target)->modify('+30 minutes');

        return $this->createQueryBuilder('c')
            ->where('c.partner = :partner')
            ->andWhere('c.collectedAt BETWEEN :from AND :to')
            ->setParameter('partner', $partner)
            ->setParameter('from', $windowFrom)
            ->setParameter('to', $windowTo)
            ->orderBy('c.collectedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
