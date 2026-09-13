<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria e ajusta as tabelas do Waze TVT.';
    }

    public function up(Schema $schema): void
    {
        /*
         * A tabela waze_tvt_route não existe.
         */
        $this->addSql(
            'CREATE TABLE waze_tvt_route (
                id INT AUTO_INCREMENT NOT NULL,
                partner_id INT NOT NULL,
                route_id VARCHAR(100) NOT NULL,
                name VARCHAR(255) DEFAULT NULL,
                from_name VARCHAR(255) DEFAULT NULL,
                to_name VARCHAR(255) DEFAULT NULL,
                length INT DEFAULT NULL,
                geometry JSON DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_seen_at DATETIME NOT NULL,
                deactivated_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_tvt_route_partner_route
                    (partner_id, route_id),
                INDEX idx_tvt_route_partner_active
                    (partner_id, is_active),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_route
             ADD CONSTRAINT fk_tvt_route_partner
             FOREIGN KEY (partner_id)
             REFERENCES partner (id)
             ON DELETE CASCADE',
        );

        /*
         * waze_tvt_sub_route
         */
        $this->addSql(
            'ALTER TABLE waze_tvt_sub_route
             ADD waze_route_id VARCHAR(100) NOT NULL,
             ADD sub_route_id VARCHAR(100) NOT NULL,
             ADD from_name VARCHAR(255) DEFAULT NULL,
             ADD to_name VARCHAR(255) DEFAULT NULL,
             ADD length INT DEFAULT NULL,
             ADD time INT DEFAULT NULL,
             ADD historic_time INT DEFAULT NULL,
             ADD jam_level INT DEFAULT NULL,
             ADD line JSON DEFAULT NULL,
             ADD bbox JSON DEFAULT NULL,
             ADD irregularities JSON DEFAULT NULL,
             ADD is_active TINYINT(1) NOT NULL DEFAULT 1,
             ADD last_seen_at DATETIME NOT NULL,
             ADD deactivated_at DATETIME DEFAULT NULL,
             ADD route_id INT NULL',
        );

        /*
         * Como a tabela antiga não possui route_id, subRouteId ou
         * dados suficientes para reconstruir a relação, a migration
         * deixa route_id temporariamente nulo.
         *
         * Se a tabela já tiver registros antigos, eles deverão ser
         * associados ao route correto depois da primeira coleta TVT.
         */
        $this->addSql(
            'ALTER TABLE waze_tvt_sub_route
             MODIFY route_id INT NULL',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_sub_route
             ADD INDEX idx_tvt_subroute_route (route_id)',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_sub_route
             ADD INDEX idx_tvt_subroute_partner_active
                 (partner_id, is_active)',
        );

        /*
         * Não criamos a unique constraint imediatamente se existirem
         * registros antigos duplicados. Ela será criada depois de
         * validar os dados existentes.
         */
        $this->addSql(
            'ALTER TABLE waze_tvt_sub_route
             ADD CONSTRAINT fk_tvt_subroute_route
             FOREIGN KEY (route_id)
             REFERENCES waze_tvt_route (id)
             ON DELETE CASCADE',
        );

        /*
         * waze_tvt_irregularity
         */
        $this->addSql(
            'ALTER TABLE waze_tvt_irregularity
             ADD waze_route_id VARCHAR(100) NOT NULL,
             ADD waze_sub_route_id VARCHAR(100) DEFAULT NULL,
             ADD content_hash VARCHAR(64) NOT NULL,
             ADD subtype VARCHAR(255) DEFAULT NULL,
             ADD description VARCHAR(255) DEFAULT NULL,
             ADD payload JSON DEFAULT NULL,
             ADD is_active TINYINT(1) NOT NULL DEFAULT 1,
             ADD recorded_at DATETIME NOT NULL,
             ADD last_seen_at DATETIME NOT NULL,
             ADD deactivated_at DATETIME DEFAULT NULL,
             ADD updated_at DATETIME NOT NULL,
             ADD route_id INT NULL,
             ADD sub_route_id INT NULL',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_irregularity
             ADD INDEX idx_tvt_irregularity_route (route_id)',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_irregularity
             ADD INDEX idx_tvt_irregularity_subroute (sub_route_id)',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_irregularity
             ADD INDEX idx_tvt_irregularity_partner_active
                 (partner_id, is_active)',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_irregularity
             ADD CONSTRAINT fk_tvt_irregularity_route
             FOREIGN KEY (route_id)
             REFERENCES waze_tvt_route (id)
             ON DELETE CASCADE',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_irregularity
             ADD CONSTRAINT fk_tvt_irregularity_subroute
             FOREIGN KEY (sub_route_id)
             REFERENCES waze_tvt_sub_route (id)
             ON DELETE CASCADE',
        );

        /*
         * waze_tvt_route_snapshot
         */
        $this->addSql(
            'ALTER TABLE waze_tvt_route_snapshot
             ADD waze_route_id VARCHAR(100) NOT NULL,
             ADD time INT DEFAULT NULL,
             ADD historic_time INT DEFAULT NULL,
             ADD jam_level INT DEFAULT NULL,
             ADD payload JSON DEFAULT NULL,
             ADD recorded_at DATETIME NOT NULL,
             ADD route_id INT NULL',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_route_snapshot
             ADD INDEX idx_tvt_snapshot_route (route_id)',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_route_snapshot
             ADD INDEX idx_tvt_snapshot_partner_recorded
                 (partner_id, recorded_at)',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_route_snapshot
             ADD INDEX idx_tvt_snapshot_route_recorded
                 (route_id, recorded_at)',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_route_snapshot
             ADD CONSTRAINT fk_tvt_snapshot_route
             FOREIGN KEY (route_id)
             REFERENCES waze_tvt_route (id)
             ON DELETE CASCADE',
        );

        /*
         * waze_tvt_user_on_jam
         */
        $this->addSql(
            'ALTER TABLE waze_tvt_user_on_jam
             ADD waze_route_id VARCHAR(100) DEFAULT NULL,
             ADD wazers_count INT DEFAULT NULL,
             ADD jam_level INT DEFAULT NULL,
             ADD payload JSON DEFAULT NULL,
             ADD recorded_at DATETIME NOT NULL,
             ADD route_id INT NULL',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_user_on_jam
             ADD INDEX idx_tvt_user_jam_route (route_id)',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_user_on_jam
             ADD INDEX idx_tvt_user_jam_partner_recorded
                 (partner_id, recorded_at)',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_user_on_jam
             ADD CONSTRAINT fk_tvt_user_jam_route
             FOREIGN KEY (route_id)
             REFERENCES waze_tvt_route (id)
             ON DELETE SET NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE waze_tvt_user_on_jam
             DROP FOREIGN KEY fk_tvt_user_jam_route',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_route_snapshot
             DROP FOREIGN KEY fk_tvt_snapshot_route',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_irregularity
             DROP FOREIGN KEY fk_tvt_irregularity_subroute',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_irregularity
             DROP FOREIGN KEY fk_tvt_irregularity_route',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_sub_route
             DROP FOREIGN KEY fk_tvt_subroute_route',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_route
             DROP FOREIGN KEY fk_tvt_route_partner',
        );

        $this->addSql('DROP TABLE waze_tvt_route');

        $this->addSql(
            'ALTER TABLE waze_tvt_sub_route
             DROP waze_route_id,
             DROP sub_route_id,
             DROP from_name,
             DROP to_name,
             DROP length,
             DROP time,
             DROP historic_time,
             DROP jam_level,
             DROP line,
             DROP bbox,
             DROP irregularities,
             DROP is_active,
             DROP last_seen_at,
             DROP deactivated_at,
             DROP route_id',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_irregularity
             DROP waze_route_id,
             DROP waze_sub_route_id,
             DROP content_hash,
             DROP subtype,
             DROP description,
             DROP payload,
             DROP is_active,
             DROP recorded_at,
             DROP last_seen_at,
             DROP deactivated_at,
             DROP updated_at,
             DROP route_id,
             DROP sub_route_id',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_route_snapshot
             DROP waze_route_id,
             DROP time,
             DROP historic_time,
             DROP jam_level,
             DROP payload,
             DROP recorded_at,
             DROP route_id',
        );

        $this->addSql(
            'ALTER TABLE waze_tvt_user_on_jam
             DROP waze_route_id,
             DROP wazers_count,
             DROP jam_level,
             DROP payload,
             DROP recorded_at,
             DROP route_id',
        );
    }
}
