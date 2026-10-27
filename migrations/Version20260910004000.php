<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910004000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing observed_at column to waze_tvt_route_history';
    }

    public function up(Schema $schema): void
    {
        $table = $this->connection->createSchemaManager()->introspectTable('waze_tvt_route_history');
        $columns = array_map(static fn ($column) => $column->getName(), $table->getColumns());

        if (!in_array('observed_at', $columns, true)) {
            $this->addSql('ALTER TABLE waze_tvt_route_history ADD observed_at DATETIME NOT NULL');
        }
        if (!in_array('travel_time_seconds', $columns, true)) {
            $this->addSql('ALTER TABLE waze_tvt_route_history ADD travel_time_seconds INT DEFAULT NULL');
        }
        if (!in_array('speed_kmh', $columns, true)) {
            $this->addSql('ALTER TABLE waze_tvt_route_history ADD speed_kmh DECIMAL(8, 2) DEFAULT NULL');
        }
        if (!in_array('delay_seconds', $columns, true)) {
            $this->addSql('ALTER TABLE waze_tvt_route_history ADD delay_seconds INT DEFAULT NULL');
        }
        if (!in_array('length_meters', $columns, true)) {
            $this->addSql('ALTER TABLE waze_tvt_route_history ADD length_meters INT DEFAULT NULL');
        }
        if (!in_array('status', $columns, true)) {
            $this->addSql('ALTER TABLE waze_tvt_route_history ADD status VARCHAR(40) DEFAULT NULL');
        }
        if (!in_array('raw_metrics', $columns, true)) {
            $this->addSql('ALTER TABLE waze_tvt_route_history ADD raw_metrics JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
    }
}
