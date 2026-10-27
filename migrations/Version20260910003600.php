<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910003600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align TVT definition columns and preserve existing foreign keys';
    }

    public function up(Schema $schema): void
    {
        $table = $this->connection->createSchemaManager()->introspectTable('waze_tvt_route_definition');
        $columns = array_map(static fn ($column) => $column->getName(), $table->getColumns());

        if (!in_array('route_id', $columns, true)) {
            $this->addSql("ALTER TABLE waze_tvt_route_definition ADD route_id VARCHAR(255) NOT NULL");
        }
        if (!in_array('name', $columns, true)) {
            $this->addSql("ALTER TABLE waze_tvt_route_definition ADD name VARCHAR(255) DEFAULT NULL");
        }
        if (!in_array('bbox', $columns, true)) {
            $this->addSql("ALTER TABLE waze_tvt_route_definition ADD bbox LONGTEXT DEFAULT NULL");
        }
        if (!in_array('line', $columns, true)) {
            $this->addSql("ALTER TABLE waze_tvt_route_definition ADD line LONGTEXT DEFAULT NULL");
        }
        if (!in_array('created_at', $columns, true)) {
            $this->addSql("ALTER TABLE waze_tvt_route_definition ADD created_at DATETIME NOT NULL");
        }
        if (!in_array('updated_at', $columns, true)) {
            $this->addSql("ALTER TABLE waze_tvt_route_definition ADD updated_at DATETIME NOT NULL");
        }
    }

    public function down(Schema $schema): void
    {
    }
}
