<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona partner, status ativo e controle de última visualização aos feeds Waze.';
    }

    public function up(Schema $schema): void
    {
        /*
         * waze_alerts
         */
        $this->addSql(
            'ALTER TABLE waze_alerts
             ADD partner_id INT NULL,
             ADD is_active TINYINT(1) NOT NULL DEFAULT 1,
             ADD last_seen_at DATETIME NULL,
             ADD deactivated_at DATETIME NULL',
        );

        /*
         * Relaciona registros antigos ao partner 1.
         * Altere este ID se os registros antigos pertencerem a outro partner.
         */
        $this->addSql(
            'UPDATE waze_alerts
             SET partner_id = 1
             WHERE partner_id IS NULL',
        );

        $this->addSql(
            'UPDATE waze_alerts
             SET last_seen_at = collected_at
             WHERE last_seen_at IS NULL',
        );

        /*
         * Remove a unicidade global antiga do UUID.
         */
        $this->addSql(
            'ALTER TABLE waze_alerts
             DROP INDEX uniq_uuid',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             MODIFY partner_id INT NOT NULL,
             MODIFY last_seen_at DATETIME NOT NULL',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             ADD UNIQUE INDEX uniq_waze_alert_partner_uuid (partner_id, uuid)',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             ADD INDEX idx_waze_alert_partner_active (partner_id, is_active)',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             ADD INDEX idx_waze_alert_last_seen_at (last_seen_at)',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             ADD CONSTRAINT fk_waze_alert_partner
             FOREIGN KEY (partner_id)
             REFERENCES partner (id)
             ON DELETE CASCADE',
        );

        /*
         * waze_jams
         */
        $this->addSql(
            'ALTER TABLE waze_jams
             ADD partner_id INT NULL,
             ADD is_active TINYINT(1) NOT NULL DEFAULT 1,
             ADD last_seen_at DATETIME NULL,
             ADD deactivated_at DATETIME NULL',
        );

        $this->addSql(
            'UPDATE waze_jams
             SET partner_id = 1
             WHERE partner_id IS NULL',
        );

        $this->addSql(
            'UPDATE waze_jams
             SET last_seen_at = collected_at
             WHERE last_seen_at IS NULL',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             DROP INDEX uniq_uuid',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             MODIFY partner_id INT NOT NULL,
             MODIFY last_seen_at DATETIME NOT NULL',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             ADD UNIQUE INDEX uniq_waze_jam_partner_uuid (partner_id, uuid)',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             ADD INDEX idx_waze_jam_partner_active (partner_id, is_active)',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             ADD INDEX idx_waze_jam_last_seen_at (last_seen_at)',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             ADD CONSTRAINT fk_waze_jam_partner
             FOREIGN KEY (partner_id)
             REFERENCES partner (id)
             ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        /*
         * O down remove apenas as alterações desta migration.
         * Não recria nem apaga as tabelas antigas.
         */
        $this->addSql(
            'ALTER TABLE waze_alerts
             DROP FOREIGN KEY fk_waze_alert_partner',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             DROP INDEX uniq_waze_alert_partner_uuid',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             DROP INDEX idx_waze_alert_partner_active',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             DROP INDEX idx_waze_alert_last_seen_at',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             ADD UNIQUE INDEX uniq_uuid (uuid)',
        );

        $this->addSql(
            'ALTER TABLE waze_alerts
             DROP partner_id,
             DROP is_active,
             DROP last_seen_at,
             DROP deactivated_at',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             DROP FOREIGN KEY fk_waze_jam_partner',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             DROP INDEX uniq_waze_jam_partner_uuid',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             DROP INDEX idx_waze_jam_partner_active',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             DROP INDEX idx_waze_jam_last_seen_at',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             ADD UNIQUE INDEX uniq_uuid (uuid)',
        );

        $this->addSql(
            'ALTER TABLE waze_jams
             DROP partner_id,
             DROP is_active,
             DROP last_seen_at,
             DROP deactivated_at',
        );
    }
}
