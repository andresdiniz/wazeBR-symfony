<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704_station_cotas extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add flood-alert threshold columns (cota_atencao, cota_alerta, cota_transbordamento) to cemaden_stations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE cemaden_stations
                ADD COLUMN cota_atencao          DECIMAL(8,2) NULL DEFAULT NULL COMMENT 'Nível de atenção (m)',
                ADD COLUMN cota_alerta           DECIMAL(8,2) NULL DEFAULT NULL COMMENT 'Nível de alerta (m)',
                ADD COLUMN cota_transbordamento  DECIMAL(8,2) NULL DEFAULT NULL COMMENT 'Nível de transbordamento (m)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE cemaden_stations
                DROP COLUMN cota_atencao,
                DROP COLUMN cota_alerta,
                DROP COLUMN cota_transbordamento
        SQL);
    }
}
