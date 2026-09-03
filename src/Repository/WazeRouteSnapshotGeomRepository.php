<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WazeRouteSnapshotGeom;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeRouteSnapshotGeom>
 */
class WazeRouteSnapshotGeomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeRouteSnapshotGeom::class);
    }

    public function upsertGeometry(WazeRouteSnapshotGeom $geom): void
    {
        $conn = $this->getEntityManager()->getConnection();
        
        $sql = '
            INSERT INTO waze_route_snapshot_geom (route_id, updated_at, line, bbox)
            VALUES (:routeId, :updatedAt, :line, :bbox)
            ON CONFLICT (route_id) DO UPDATE SET
                updated_at = EXCLUDED.updated_at,
                line = EXCLUDED.line,
                bbox = EXCLUDED.bbox
        ';
        
        $conn->executeStatement($sql, [
            'routeId' => $geom->getRoute()->getId(),
            'updatedAt' => $geom->getUpdatedAt()->format('Y-m-d H:i:s'),
            'line' => $geom->getLine() ? json_encode($geom->getLine(), JSON_UNESCAPED_UNICODE) : null,
            'bbox' => $geom->getBbox() ? json_encode($geom->getBbox(), JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public function findByRoute(int $routeId): ?WazeRouteSnapshotGeom
    {
        return $this->createQueryBuilder('g')
            ->where('g.route = :routeId')
            ->setParameter('routeId', $routeId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
