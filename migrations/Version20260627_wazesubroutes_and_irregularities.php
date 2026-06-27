<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * - ALTER wazesubroutes: adiciona avg_speed, historic_speed e campos lead_alert_*
 * - CREATE waze_irregularities
 */
final class Version20260627_wazesubroutes_and_irregularities extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona avg_speed/historic_speed/lead_alert em wazesubroutes; cria waze_irregularities';
    }

    public function up(Schema $schema): void
    {
        // ── wazesubroutes: novos campos ───────────────────────────────────
        $this->addSql("ALTER TABLE wazesubroutes
            ADD COLUMN IF NOT EXISTS avg_speed          DOUBLE PRECISION DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS historic_speed     DOUBLE PRECISION DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS lead_alert_id      VARCHAR(64)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS lead_alert_type    VARCHAR(64)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS lead_alert_sub_type VARCHAR(64) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS lead_alert_position LONGTEXT    DEFAULT NULL COMMENT 'JSON',
            ADD COLUMN IF NOT EXISTS lead_alert_num_comments           INT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS lead_alert_num_thumbs_up          INT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS lead_alert_num_not_there_reports  INT DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS lead_alert_street VARCHAR(255) DEFAULT NULL
        ");

        // ── waze_irregularities ───────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS waze_irregularities (
                id               INT AUTO_INCREMENT NOT NULL,
                waze_id          VARCHAR(64)  DEFAULT NULL,
                partner_id       INT          DEFAULT NULL,
                source_link_id   INT          DEFAULT NULL,
                name             VARCHAR(255) DEFAULT NULL,
                from_name        VARCHAR(255) DEFAULT NULL,
                to_name          VARCHAR(255) DEFAULT NULL,
                length           INT          DEFAULT NULL,
                time             INT          DEFAULT NULL,
                historic_time    INT          DEFAULT NULL,
                jam_level        INT          DEFAULT NULL,
                avg_speed        DOUBLE PRECISION DEFAULT NULL,
                historic_speed   DOUBLE PRECISION DEFAULT NULL,
                bbox             LONGTEXT     DEFAULT NULL COMMENT 'JSON',
                line             LONGTEXT     DEFAULT NULL COMMENT 'JSON',
                is_active        TINYINT(1)   DEFAULT 1,
                collected_at     DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                lead_alert_id                   VARCHAR(64)  DEFAULT NULL,
                lead_alert_type                 VARCHAR(64)  DEFAULT NULL,
                lead_alert_sub_type             VARCHAR(64)  DEFAULT NULL,
                lead_alert_position             LONGTEXT     DEFAULT NULL COMMENT 'JSON',
                lead_alert_num_comments         INT          DEFAULT NULL,
                lead_alert_city                 VARCHAR(255) DEFAULT NULL,
                lead_alert_external_image_id    VARCHAR(255) DEFAULT NULL,
                lead_alert_num_thumbs_up        INT          DEFAULT NULL,
                lead_alert_street               VARCHAR(255) DEFAULT NULL,
                lead_alert_num_not_there_reports INT         DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uq_irr_waze_link (waze_id, source_link_id),
                INDEX IDX_irr_partner    (partner_id),
                INDEX IDX_irr_link       (source_link_id),
                INDEX IDX_irr_is_active  (is_active),
                CONSTRAINT FK_irr_partner
                    FOREIGN KEY (partner_id)     REFERENCES partners (id) ON DELETE SET NULL,
                CONSTRAINT FK_irr_source_link
                    FOREIGN KEY (source_link_id) REFERENCES monitored_links (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waze_irregularities');
        $this->addSql("ALTER TABLE wazesubroutes
            DROP COLUMN IF EXISTS avg_speed,
            DROP COLUMN IF EXISTS historic_speed,
            DROP COLUMN IF EXISTS lead_alert_id,
            DROP COLUMN IF EXISTS lead_alert_type,
            DROP COLUMN IF EXISTS lead_alert_sub_type,
            DROP COLUMN IF EXISTS lead_alert_position,
            DROP COLUMN IF EXISTS lead_alert_num_comments,
            DROP COLUMN IF EXISTS lead_alert_num_thumbs_up,
            DROP COLUMN IF EXISTS lead_alert_num_not_there_reports,
            DROP COLUMN IF EXISTS lead_alert_street
        ");
    }
}
