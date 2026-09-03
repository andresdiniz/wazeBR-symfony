<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeRouteSnapshotLight;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeRouteSnapshotLight>
 */
class WazeRouteSnapshotLightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeRouteSnapshotLight::class);
    }

    public function upsertSnapshot(WazeRouteSnapshotLight $snapshot): void
    {
        $conn = $this->getEntityManager()->getConnection();
        
        $sql = '
            INSERT INTO waze_route_snapshot_light (route_id, recorded_at, speed, length, delay, traffic_level)
            VALUES (:routeId, :recordedAt, :speed, :length, :delay, :trafficLevel)
            ON CONFLICT (route_id, recorded_at) DO UPDATE SET
                speed = EXCLUDED.speed,
                length = EXCLUDED.length,
                delay = EXCLUDED.delay,
                traffic_level = EXCLUDED.traffic_level
        ';
        
        $conn->executeStatement($sql, [
            'routeId' => $snapshot->getRoute()->getId(),
            'recordedAt' => $snapshot->getRecordedAt()->format('Y-m-d H:i:s'),
            'speed' => $snapshot->getSpeed(),
            'length' => $snapshot->getLength(),
            'delay' => $snapshot->getDelay(),
            'trafficLevel' => $snapshot->getTrafficLevel(),
        ]);
    }

    public function findRecentByRoute(int $routeId, int $limit = 100): array
    {
        return $this->createQueryBuilder('s')
            ->select('s')
            ->where('s.route = :routeId')
            ->setParameter('routeId', $routeId)
            ->orderBy('s.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
