<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona campos completos ao WazeTrafficJam (TVT) e unique index no wazeId.
 */
final class Version20260624_waze_traffic_jam_complete extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WazeTrafficJam: sourceLink, country, type, turnType, roadType, startNode, endNode, causedBy, segments, feedStartMillis, updatedAt, unique wazeId';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_traffic_jams
            ADD COLUMN IF NOT EXISTS source_link_id    INT          DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS country           VARCHAR(10)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS type              VARCHAR(40)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS turn_type         VARCHAR(40)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS road_type         INT          DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS start_node        VARCHAR(200) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS end_node          VARCHAR(200) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS caused_by         VARCHAR(80)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS segments          JSON         NOT NULL DEFAULT (JSON_ARRAY()),
            ADD COLUMN IF NOT EXISTS feed_start_millis BIGINT       DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS updated_at        DATETIME     DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)",
            MODIFY COLUMN line JSON NOT NULL DEFAULT (JSON_ARRAY())
        ');

        $this->addSql('ALTER TABLE waze_traffic_jams
            ADD CONSTRAINT IF NOT EXISTS fk_waze_jam_source_link
            FOREIGN KEY (source_link_id) REFERENCES monitored_links(id) ON DELETE SET NULL
        ');

        $this->addSql('
            ALTER TABLE waze_traffic_jams
            ADD UNIQUE INDEX IF NOT EXISTS uq_waze_jam_uuid (waze_id)
        ');

        $this->addSql('
            CREATE INDEX IF NOT EXISTS idx_jam_pubmillis ON waze_traffic_jams (pub_millis)
        ');

        $this->addSql('
            CREATE INDEX IF NOT EXISTS idx_jam_partner_pub ON waze_traffic_jams (partner_id, pub_millis)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_traffic_jams
            DROP FOREIGN KEY IF EXISTS fk_waze_jam_source_link,
            DROP INDEX IF EXISTS uq_waze_jam_uuid,
            DROP INDEX IF EXISTS idx_jam_pubmillis,
            DROP INDEX IF EXISTS idx_jam_partner_pub,
            DROP COLUMN IF EXISTS source_link_id,
            DROP COLUMN IF EXISTS country,
            DROP COLUMN IF EXISTS type,
            DROP COLUMN IF EXISTS turn_type,
            DROP COLUMN IF EXISTS road_type,
            DROP COLUMN IF EXISTS start_node,
            DROP COLUMN IF EXISTS end_node,
            DROP COLUMN IF EXISTS caused_by,
            DROP COLUMN IF EXISTS segments,
            DROP COLUMN IF EXISTS feed_start_millis,
            DROP COLUMN IF EXISTS updated_at
        ');
    }
}
