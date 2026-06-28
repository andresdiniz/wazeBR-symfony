<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria a tabela cemaden_hydro_readings para armazenar leituras das
 * estações hidrológicas CEMADEN.
 *
 * Lógica de nível:
 *   river_level = offset_value - sensor_value
 *   is_offline  = 1 quando offset IS NULL (sensor sem calibração)
 */
final class Version20260628_hydro_readings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cemaden_hydro_readings table for hydrological station data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE cemaden_hydro_readings (
                id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                station_id    INT UNSIGNED     NOT NULL  COMMENT 'FK cemaden_stations.id',
                measured_at   DATETIME         NOT NULL  COMMENT 'Horário da leitura (datahora da API)',
                sensor_value  DECIMAL(8,3)     NULL      COMMENT 'Leitura bruta do sensor (distância âmina->sensor)',
                offset_value  DECIMAL(8,3)     NULL      COMMENT 'Offset fundo->sensor (null = sensor offline)',
                river_level   DECIMAL(8,3)     NULL      COMMENT 'Nível do rio = offset - sensor (null se offline)',
                is_offline    TINYINT(1)       NOT NULL DEFAULT 0  COMMENT '1 quando offset é null',
                created_at    DATETIME         NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_hydro_station_time (station_id, measured_at),
                KEY idx_hydro_station_at (station_id, measured_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Leituras horárias das estações hidrológicas CEMADEN'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cemaden_hydro_readings');
    }
}
