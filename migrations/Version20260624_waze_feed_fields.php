<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona campos de rastreamento ao MonitoredLink e campos completos ao WazeAlert.
 */
final class Version20260624_waze_feed_fields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campos de feed Waze: MonitoredLink (feedUuid, wazePartnerId, lastFetch*) + WazeAlert (sourceLink, nThumbsUp, reportDescription, magvar, roadType, additionalInfo, comments, feedStartMillis, updatedAt, uniqueConstraint wazeId)';
    }

    public function up(Schema $schema): void
    {
        // ── MonitoredLink: novos campos ────────────────────────────────────────
        $this->addSql('ALTER TABLE monitored_links
            ADD COLUMN IF NOT EXISTS feed_uuid           VARCHAR(80)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS waze_partner_id     VARCHAR(40)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS last_fetched_at     DATETIME     DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
            ADD COLUMN IF NOT EXISTS last_fetch_count    INT          DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS last_error_message  LONGTEXT     DEFAULT NULL,
            MODIFY COLUMN url VARCHAR(500) NOT NULL
        ');

        // ── WazeAlert: novos campos ────────────────────────────────────────────
        $this->addSql('ALTER TABLE waze_alerts
            ADD COLUMN IF NOT EXISTS source_link_id      INT          DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS n_thumbs_up         INT          DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS report_description  LONGTEXT     DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS magvar              INT          DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS road_type           INT          DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS additional_info     LONGTEXT     DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS comments            JSON         NOT NULL DEFAULT (JSON_ARRAY()),
            ADD COLUMN IF NOT EXISTS feed_start_millis   BIGINT       DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS updated_at          DATETIME     DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)"
        ');

        // FK para MonitoredLink
        $this->addSql('ALTER TABLE waze_alerts
            ADD CONSTRAINT IF NOT EXISTS fk_waze_alert_source_link
            FOREIGN KEY (source_link_id) REFERENCES monitored_links(id) ON DELETE SET NULL
        ');

        // Unique constraint no wazeId (pode já existir — ignora se existir)
        $this->addSql('
            ALTER TABLE waze_alerts
            ADD UNIQUE INDEX IF NOT EXISTS uq_waze_alert_uuid (waze_id)
        ');

        // Índice composto partner + pubMillis para queries de dashboard
        $this->addSql('
            CREATE INDEX IF NOT EXISTS idx_partner_pub ON waze_alerts (partner_id, pub_millis)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_alerts
            DROP FOREIGN KEY IF EXISTS fk_waze_alert_source_link,
            DROP INDEX IF EXISTS uq_waze_alert_uuid,
            DROP INDEX IF EXISTS idx_partner_pub,
            DROP COLUMN IF EXISTS source_link_id,
            DROP COLUMN IF EXISTS n_thumbs_up,
            DROP COLUMN IF EXISTS report_description,
            DROP COLUMN IF EXISTS magvar,
            DROP COLUMN IF EXISTS road_type,
            DROP COLUMN IF EXISTS additional_info,
            DROP COLUMN IF EXISTS comments,
            DROP COLUMN IF EXISTS feed_start_millis,
            DROP COLUMN IF EXISTS updated_at
        ');

        $this->addSql('ALTER TABLE monitored_links
            DROP COLUMN IF EXISTS feed_uuid,
            DROP COLUMN IF EXISTS waze_partner_id,
            DROP COLUMN IF EXISTS last_fetched_at,
            DROP COLUMN IF EXISTS last_fetch_count,
            DROP COLUMN IF EXISTS last_error_message,
            MODIFY COLUMN url VARCHAR(255) NOT NULL
        ');
    }
}
