<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria a tabela cemaden_hydro_data para armazenar leituras hidrológicas CEMADEN.
 *
 * Diferente de cemaden_hydro_readings (que usa FK para station_id),
 * esta tabela é desnormalizada: armazena código, nome, município e UF
 * diretamente na linha, seguindo o mesmo padrão de CemadenData (pluviométrico).
 *
 * Índice único (station_code, measured_at) garante idempotência nos re-runs
 * do scheduler de 10 em 10 minutos.
 */
final class Version20260704000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cemaden_hydro_data table (desnormalized hydrological readings)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cemaden_hydro_data (
                id                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                station_code         VARCHAR(30)      NOT NULL  COMMENT 'Código CEMADEN (ex: 311830410H)',
                station_name         VARCHAR(120)     NOT NULL  COMMENT 'Nome da estação (ex: Rio Bananeiras)',
                municipality         VARCHAR(120)     NOT NULL  COMMENT 'Município',
                state                VARCHAR(2)       NOT NULL  COMMENT 'UF',
                water_level          DECIMAL(8,3)     NULL      COMMENT 'Nível do rio em metros (campo valor da API)',
                offset_value         DECIMAL(8,3)     NULL      COMMENT 'Offset de calibração (campo offset da API)',
                qualificacao         VARCHAR(10)      NULL      COMMENT 'Qualificação da medida (ex: 0000)',
                cota_atencao         DECIMAL(6,2)     NULL      COMMENT 'Cota de atenção em metros',
                cota_alerta          DECIMAL(6,2)     NULL      COMMENT 'Cota de alerta em metros',
                cota_transbordamento DECIMAL(6,2)     NULL      COMMENT 'Cota de transbordamento em metros',
                alert_level          VARCHAR(20)      NULL      COMMENT 'normal | atencao | alerta | transbordamento',
                partner_id           INT              NULL      COMMENT 'FK partners.id (SET NULL on delete)',
                measured_at          DATETIME         NOT NULL  COMMENT 'Data/hora da medição (datahora da API)',
                created_at           DATETIME         NOT NULL  COMMENT 'Data/hora de inserção no banco',
                PRIMARY KEY (id),
                UNIQUE KEY uniq_hydro_station_time (station_code, measured_at),
                KEY idx_hydro_station    (station_code),
                KEY idx_hydro_measured   (measured_at),
                KEY idx_hydro_partner    (partner_id),
                CONSTRAINT fk_hydro_partner
                    FOREIGN KEY (partner_id) REFERENCES partners (id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Leituras hidrológicas CEMADEN (station_type = hydrological)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cemaden_hydro_data');
    }
}
