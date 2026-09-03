<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902235900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Otimiza\u00e7\u00e3o de snapshots - separa\u00e7\u00e3o de dados leves e geom\u00e9tricos';
    }

    public function up(): void
    {
        $this->createSnapshotLightTable();
        $this->createSnapshotGeomTable();
        $this->migrateExistingData();
    }

    public function down(): void
    {
        $this->dropTable('waze_route_snapshot_geom');
        $this->dropTable('waze_route_snapshot_light');
    }

    private function createSnapshotLightTable(): void
    {
        $table = new Table('waze_route_snapshot_light');
        $table->addColumn('id', Types::INTEGER)->setAutoincrement(true)->setNotnull(true);
        $table->addColumn('route_id', Types::INTEGER)->setNotnull(true);
        $table->addColumn('recorded_at', Types::DATETIME_IMMUTABLE)->setNotnull(true);
        $table->addColumn('speed', Types::FLOAT)->setNotnull(false);
        $table->addColumn('length', Types::FLOAT)->setNotnull(false);
        $table->addColumn('delay', Types::FLOAT)->setNotnull(false);
        $table->addColumn('traffic_level', Types::INTEGER)->setNotnull(false);
        
        $table->setPrimaryKey(['id']);
        $table->addIndex(['route_id', 'recorded_at']);
        $table->addUniqueIndex(['route_id', 'recorded_at']);
        
        $this->createTable($table);
    }

    private function createSnapshotGeomTable(): void
    {
        $table = new Table('waze_route_snapshot_geom');
        $table->addColumn('id', Types::INTEGER)->setAutoincrement(true)->setNotnull(true);
        $table->addColumn('route_id', Types::INTEGER)->setNotnull(true);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE)->setNotnull(true);
        $table->addColumn('line', Types::JSON)->setNotnull(false);
        $table->addColumn('bbox', Types::JSON)->setNotnull(false);
        
        $table->setPrimaryKey(['id']);
        $table->addIndex(['route_id']);
        $table->addUniqueIndex(['route_id']);
        
        $this->createTable($table);
    }

    private function migrateExistingData(): void
    {
        $this->addSql("
            INSERT INTO waze_route_snapshot_light (route_id, recorded_at, speed, length, delay, traffic_level)
            SELECT route_id, recorded_at, speed, length, delay, traffic_level
            FROM waze_route_snapshot
            WHERE recorded_at >= NOW() - INTERVAL '30 days'
            ORDER BY recorded_at DESC
        ");
        
        $this->addSql("
            INSERT INTO waze_route_snapshot_geom (route_id, updated_at, line, bbox)
            SELECT DISTINCT ON (route_id) route_id, recorded_at, line, bbox
            FROM waze_route_snapshot
            WHERE line IS NOT NULL OR bbox IS NOT NULL
            ORDER BY route_id, recorded_at DESC
        ");
    }
}
