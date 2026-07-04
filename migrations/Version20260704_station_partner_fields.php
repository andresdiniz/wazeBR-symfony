<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds partner_id (FK -> partners.id) and is_active to cemaden_stations.
 * These columns are required by CemadenHydroDataRepository queries.
 */
final class Version20260704_station_partner_fields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add partner_id (FK) and is_active to cemaden_stations';
    }

    public function up(Schema $schema): void
    {
        // Add partner_id — nullable so existing rows don\'t break
        $this->addSql(<<<'SQL'
            ALTER TABLE cemaden_stations
                ADD COLUMN partner_id INT NULL DEFAULT NULL COMMENT 'FK partners.id',
                ADD COLUMN is_active  TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = desativada'
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE cemaden_stations
                ADD CONSTRAINT FK_cemaden_stations_partner
                FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE SET NULL
        SQL);

        $this->addSql('CREATE INDEX IDX_cemaden_stations_partner ON cemaden_stations (partner_id)');
        $this->addSql('CREATE INDEX IDX_cemaden_stations_active  ON cemaden_stations (is_active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cemaden_stations DROP FOREIGN KEY FK_cemaden_stations_partner');
        $this->addSql('DROP INDEX IDX_cemaden_stations_partner ON cemaden_stations');
        $this->addSql('DROP INDEX IDX_cemaden_stations_active  ON cemaden_stations');
        $this->addSql(<<<'SQL'
            ALTER TABLE cemaden_stations
                DROP COLUMN partner_id,
                DROP COLUMN is_active
        SQL);
    }
}
