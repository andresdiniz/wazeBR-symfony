<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902235900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Otimizacao de snapshots - separacao de dados leves e geometricos';
    }

    public function up(Schema $schema): void
    {
        $this->createSnapshotLightTable($schema);
        $this->createSnapshotGeomTable($schema);
        $this->migrateExistingData();
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('waze_route_snapshot_geom');
        $schema->dropTable('waze_route_snapshot_light');
    }

    private function createSnapshotLightTable(Schema $schema): void
    {
        $table = $schema->createTable('waze_route_snapshot_light');
        $table->addColumn('id', 'integer')->setAutoincrement(true)->setNotnull(true);
        $table->addColumn('route_id', 'integer')->setNotnull(true);
        $table->addColumn('recorded_at', 'datetime')->setNotnull(true);
        $table->addColumn('speed', 'float')->setNotnull(false);
        $table->addColumn('length', 'float')->setNotnull(false);
        $table->addColumn('delay', 'float')->setNotnull(false);
        $table->addColumn('traffic_level', 'integer')->setNotnull(false);
        
        $table->setPrimaryKey(['id']);
        $table->addIndex(['route_id', 'recorded_at']);
        $table->addUniqueIndex(['route_id', 'recorded_at']);
    }

    private function createSnapshotGeomTable(Schema $schema): void
    {
        $table = $schema->createTable('waze_route_snapshot_geom');
        $table->addColumn('id', 'integer')->setAutoincrement(true)->setNotnull(true);
        $table->addColumn('route_id', 'integer')->setNotnull(true);
        $table->addColumn('updated_at', 'datetime')->setNotnull(true);
        $table->addColumn('line', 'json')->setNotnull(false);
        $table->addColumn('bbox', 'json')->setNotnull(false);
        
        $table->setPrimaryKey(['id']);
        $table->addIndex(['route_id']);
        $table->addUniqueIndex(['route_id']);
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
