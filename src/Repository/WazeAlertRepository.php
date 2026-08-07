<?php

namespace App\Repository;

use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeAlert>
 */
class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    public function getAlertsPerHourLast24h(): array
    {
        $now = time() * 1000;
        $lastDay = $now - 24 * 3600 * 1000;

        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT
                CONCAT(DATE_FORMAT(FROM_UNIXTIME(a.pub_millis / 1000), '%H'), 'h') AS hour_label,
                COUNT(a.id) AS total
            FROM waze_alerts a
            WHERE a.pub_millis >= :lastDay
            GROUP BY hour_label
            ORDER BY hour_label ASC
        SQL;

        return $conn->fetchAllAssociative($sql, [
            'lastDay' => $lastDay,
        ]);
    }
}
