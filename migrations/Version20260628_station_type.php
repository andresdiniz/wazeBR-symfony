<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260628_station_type extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add station_type (pluviometric|hydrological|meteorological) and hydro_url to cemaden_stations';
    }

    public function up(Schema $schema): void
    {
        // station_type: padrão pluviometric para registros existentes
        $this->addSql("
            ALTER TABLE cemaden_stations
            ADD COLUMN station_type ENUM('pluviometric','hydrological','meteorological')
                NOT NULL DEFAULT 'pluviometric'
                COMMENT 'Tipo da estação CEMADEN'
            AFTER uf
        ");

        // hydro_url: URL da API hidrológica (apenas para tipo hydrological)
        $this->addSql("
            ALTER TABLE cemaden_stations
            ADD COLUMN hydro_url VARCHAR(512) NULL DEFAULT NULL
                COMMENT 'URL JSON da API hidrológica CEMADEN (ex: recursos/AcumuladoResource.php?est=...)'
            AFTER station_type
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cemaden_stations DROP COLUMN hydro_url');
        $this->addSql('ALTER TABLE cemaden_stations DROP COLUMN station_type');
    }
}
