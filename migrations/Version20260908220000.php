<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refatoração Waze: feeds por partner, deduplicação e ciclo de vida';
    }

    public function up(Schema $schema): void
    {
        // ── 1. waze_feed (já existe — pula criação) ──────────────────────────
        // ── 2. waze_feed_collection (já existe — pula criação) ───────────────

        // ── 3. waze_alert — novos campos ─────────────────────────────────────
        $existing = $this->getExistingColumns('waze_alert');

        if (!in_array('partner_id', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD partner_id INT NOT NULL DEFAULT 1');
            $this->addSql('ALTER TABLE waze_alert ADD CONSTRAINT FK_WAZE_ALERT_PARTNER FOREIGN KEY (partner_id) REFERENCES partner (id)');
        }
        if (!in_array('waze_feed_id', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD waze_feed_id BIGINT NOT NULL DEFAULT 1');
            $this->addSql('ALTER TABLE waze_alert ADD CONSTRAINT FK_WAZE_ALERT_FEED FOREIGN KEY (waze_feed_id) REFERENCES waze_feed (id)');
        }
        if (!in_array('last_seen_collection_id', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD last_seen_collection_id BIGINT DEFAULT NULL');
            $this->addSql('ALTER TABLE waze_alert ADD CONSTRAINT FK_WAZE_ALERT_COLLECTION FOREIGN KEY (last_seen_collection_id) REFERENCES waze_feed_collection (id)');
        }
        if (!in_array('external_uuid', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD external_uuid VARCHAR(50) DEFAULT NULL');
        }
        if (!in_array('dedup_key', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD dedup_key VARCHAR(64) NOT NULL DEFAULT \'\'');
        }
        if (!in_array('semantic_cluster_key', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD semantic_cluster_key VARCHAR(64) DEFAULT NULL');
        }
        if (!in_array('latitude', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD latitude DECIMAL(10,7) NOT NULL DEFAULT 0');
        }
        if (!in_array('longitude', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD longitude DECIMAL(10,7) NOT NULL DEFAULT 0');
        }
        if (!in_array('geohash', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD geohash VARCHAR(12) NOT NULL DEFAULT \'\'');
        }
        if (!in_array('street', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD street VARCHAR(255) DEFAULT NULL');
        }
        if (!in_array('street_normalized', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD street_normalized VARCHAR(255) DEFAULT NULL');
        }
        if (!in_array('city', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD city VARCHAR(150) DEFAULT NULL');
        }
        if (!in_array('country', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD country VARCHAR(2) DEFAULT NULL');
        }
        if (!in_array('road_type', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD road_type SMALLINT DEFAULT NULL');
        }
        if (!in_array('description', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD description TEXT DEFAULT NULL');
        }
        if (!in_array('confidence', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD confidence SMALLINT DEFAULT NULL');
        }
        if (!in_array('reliability', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD reliability SMALLINT DEFAULT NULL');
        }
        if (!in_array('report_rating', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD report_rating SMALLINT DEFAULT NULL');
        }
        if (!in_array('thumbs_up', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD thumbs_up INT DEFAULT NULL');
        }
        if (!in_array('magvar', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD magvar SMALLINT DEFAULT NULL');
        }
        if (!in_array('reported_at', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD reported_at DATETIME DEFAULT NULL');
        }
        if (!in_array('first_seen_at', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD first_seen_at DATETIME NOT NULL DEFAULT NOW()');
        }
        if (!in_array('last_seen_at', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD last_seen_at DATETIME NOT NULL DEFAULT NOW()');
        }
        if (!in_array('missing_since_at', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD missing_since_at DATETIME DEFAULT NULL');
        }
        if (!in_array('deactivated_at', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD deactivated_at DATETIME DEFAULT NULL');
        }
        if (!in_array('is_active', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
        }
        if (!in_array('raw_payload', $existing)) {
            $this->addSql('ALTER TABLE waze_alert ADD raw_payload JSON DEFAULT NULL');
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_WAZE_ALERT_EXTERNAL    ON waze_alert (partner_id, waze_feed_id, external_uuid)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_WAZE_ALERT_DEDUP       ON waze_alert (partner_id, dedup_key)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_WAZE_ALERT_ACTIVE_SEEN ON waze_alert (partner_id, is_active, last_seen_at DESC)');

        // ── 4. waze_traffic_jam — novos campos ───────────────────────────────
        $existing = $this->getExistingColumns('waze_traffic_jam');

        if (!in_array('partner_id', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD partner_id INT NOT NULL DEFAULT 1');
            $this->addSql('ALTER TABLE waze_traffic_jam ADD CONSTRAINT FK_WAZE_JAM_PARTNER FOREIGN KEY (partner_id) REFERENCES partner (id)');
        }
        if (!in_array('waze_feed_id', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD waze_feed_id BIGINT NOT NULL DEFAULT 1');
            $this->addSql('ALTER TABLE waze_traffic_jam ADD CONSTRAINT FK_WAZE_JAM_FEED FOREIGN KEY (waze_feed_id) REFERENCES waze_feed (id)');
        }
        if (!in_array('last_seen_collection_id', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD last_seen_collection_id BIGINT DEFAULT NULL');
            $this->addSql('ALTER TABLE waze_traffic_jam ADD CONSTRAINT FK_WAZE_JAM_COLLECTION FOREIGN KEY (last_seen_collection_id) REFERENCES waze_feed_collection (id)');
        }
        if (!in_array('external_id', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD external_id INT DEFAULT NULL');
        }
        if (!in_array('external_uuid', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD external_uuid VARCHAR(50) DEFAULT NULL');
        }
        if (!in_array('dedup_key', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD dedup_key VARCHAR(64) NOT NULL DEFAULT \'\'');
        }
        if (!in_array('street', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD street VARCHAR(255) DEFAULT NULL');
        }
        if (!in_array('street_normalized', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD street_normalized VARCHAR(255) DEFAULT NULL');
        }
        if (!in_array('city', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD city VARCHAR(150) DEFAULT NULL');
        }
        if (!in_array('country', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD country VARCHAR(2) DEFAULT NULL');
        }
        if (!in_array('road_type', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD road_type SMALLINT DEFAULT NULL');
        }
        if (!in_array('start_latitude', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD start_latitude DECIMAL(10,7) DEFAULT NULL');
        }
        if (!in_array('start_longitude', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD start_longitude DECIMAL(10,7) DEFAULT NULL');
        }
        if (!in_array('end_latitude', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD end_latitude DECIMAL(10,7) DEFAULT NULL');
        }
        if (!in_array('end_longitude', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD end_longitude DECIMAL(10,7) DEFAULT NULL');
        }
        if (!in_array('geometry_hash', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD geometry_hash VARCHAR(64) DEFAULT NULL');
        }
        if (!in_array('geometry', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD geometry JSON DEFAULT NULL');
        }
        if (!in_array('length_meters', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD length_meters INT DEFAULT NULL');
        }
        if (!in_array('speed_kmh', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD speed_kmh DECIMAL(8,2) DEFAULT NULL');
        }
        if (!in_array('speed_mps', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD speed_mps DECIMAL(8,2) DEFAULT NULL');
        }
        if (!in_array('delay_seconds', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD delay_seconds INT DEFAULT NULL');
        }
        if (!in_array('level', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD level SMALLINT DEFAULT NULL');
        }
        if (!in_array('turn_type', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD turn_type VARCHAR(50) DEFAULT NULL');
        }
        if (!in_array('blocking_alert_uuid', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD blocking_alert_uuid VARCHAR(50) DEFAULT NULL');
        }
        if (!in_array('published_at', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD published_at DATETIME DEFAULT NULL');
        }
        if (!in_array('first_seen_at', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD first_seen_at DATETIME NOT NULL DEFAULT NOW()');
        }
        if (!in_array('last_seen_at', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD last_seen_at DATETIME NOT NULL DEFAULT NOW()');
        }
        if (!in_array('missing_since_at', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD missing_since_at DATETIME DEFAULT NULL');
        }
        if (!in_array('deactivated_at', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD deactivated_at DATETIME DEFAULT NULL');
        }
        if (!in_array('is_active', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
        }
        if (!in_array('raw_payload', $existing)) {
            $this->addSql('ALTER TABLE waze_traffic_jam ADD raw_payload JSON DEFAULT NULL');
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_WAZE_JAM_EXTERNAL    ON waze_traffic_jam (partner_id, waze_feed_id, external_uuid)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_WAZE_JAM_DEDUP       ON waze_traffic_jam (partner_id, dedup_key)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_WAZE_JAM_ACTIVE_SEEN ON waze_traffic_jam (partner_id, is_active, last_seen_at DESC)');

        // ── 5. waze_tvt_route — novos campos ─────────────────────────────────
        $existing = $this->getExistingColumns('waze_tvt_route');

        if (!in_array('partner_id', $existing)) {
            $this->addSql('ALTER TABLE waze_tvt_route ADD partner_id INT NOT NULL DEFAULT 1');
            $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_WAZE_TVT_ROUTE_PARTNER FOREIGN KEY (partner_id) REFERENCES partner (id)');
        }
        if (!in_array('waze_feed_id', $existing)) {
            $this->addSql('ALTER TABLE waze_tvt_route ADD waze_feed_id BIGINT NOT NULL DEFAULT 1');
            $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_WAZE_TVT_ROUTE_FEED FOREIGN KEY (waze_feed_id) REFERENCES waze_feed (id)');
        }
        if (!in_array('current_definition_id', $existing)) {
            $this->addSql('ALTER TABLE waze_tvt_route ADD current_definition_id BIGINT DEFAULT NULL');
            $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_WAZE_TVT_ROUTE_CURRENT_DEF FOREIGN KEY (current_definition_id) REFERENCES waze_tvt_route_definition (id)');
        }
        if (!in_array('external_route_id', $existing)) {
            $this->addSql('ALTER TABLE waze_tvt_route ADD external_route_id VARCHAR(80) NOT NULL DEFAULT \'\'');
        }
        if (!in_array('external_uuid', $existing)) {
            $this->addSql('ALTER TABLE waze_tvt_route ADD external_uuid VARCHAR(80) DEFAULT NULL');
        }
        if (!in_array('label', $existing)) {
            $this->addSql('ALTER TABLE waze_tvt_route ADD label VARCHAR(200) DEFAULT NULL');
        }
        if (!in_array('is_active', $existing)) {
            $this->addSql('ALTER TABLE waze_tvt_route ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
        }
        if (!in_array('first_seen_at', $existing)) {
            $this->addSql('ALTER TABLE waze_tvt_route ADD first_seen_at DATETIME NOT NULL DEFAULT NOW()');
        }
        if (!in_array('last_seen_at', $existing)) {
            $this->addSql('ALTER TABLE waze_tvt_route ADD last_seen_at DATETIME NOT NULL DEFAULT NOW()');
        }

        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_WAZE_TVT_ROUTE_EXTERNAL ON waze_tvt_route (partner_id, waze_feed_id, external_route_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UQ_WAZE_TVT_ROUTE ON waze_tvt_route (waze_feed_id, external_route_id)');
    }

    public function down(Schema $schema): void
    {
        // Remover colunas adicionadas em waze_tvt_route
        $this->addSql('ALTER TABLE waze_tvt_route DROP FOREIGN KEY FK_WAZE_TVT_ROUTE_PARTNER');
        $this->addSql('ALTER TABLE waze_tvt_route DROP FOREIGN KEY FK_WAZE_TVT_ROUTE_FEED');
        $this->addSql('ALTER TABLE waze_tvt_route DROP FOREIGN KEY FK_WAZE_TVT_ROUTE_CURRENT_DEF');
        $this->addSql('ALTER TABLE waze_tvt_route DROP COLUMN partner_id, DROP COLUMN waze_feed_id, DROP COLUMN current_definition_id, DROP COLUMN external_route_id, DROP COLUMN external_uuid, DROP COLUMN label, DROP COLUMN is_active, DROP COLUMN first_seen_at, DROP COLUMN last_seen_at');

        // Remover colunas adicionadas em waze_traffic_jam
        $this->addSql('ALTER TABLE waze_traffic_jam DROP FOREIGN KEY FK_WAZE_JAM_PARTNER');
        $this->addSql('ALTER TABLE waze_traffic_jam DROP FOREIGN KEY FK_WAZE_JAM_FEED');
        $this->addSql('ALTER TABLE waze_traffic_jam DROP FOREIGN KEY FK_WAZE_JAM_COLLECTION');
        $this->addSql('ALTER TABLE waze_traffic_jam DROP COLUMN partner_id, DROP COLUMN waze_feed_id, DROP COLUMN last_seen_collection_id, DROP COLUMN external_id, DROP COLUMN external_uuid, DROP COLUMN dedup_key, DROP COLUMN street, DROP COLUMN street_normalized, DROP COLUMN city, DROP COLUMN country, DROP COLUMN road_type, DROP COLUMN start_latitude, DROP COLUMN start_longitude, DROP COLUMN end_latitude, DROP COLUMN end_longitude, DROP COLUMN geometry_hash, DROP COLUMN geometry, DROP COLUMN length_meters, DROP COLUMN speed_kmh, DROP COLUMN speed_mps, DROP COLUMN delay_seconds, DROP COLUMN level, DROP COLUMN turn_type, DROP COLUMN blocking_alert_uuid, DROP COLUMN published_at, DROP COLUMN first_seen_at, DROP COLUMN last_seen_at, DROP COLUMN missing_since_at, DROP COLUMN deactivated_at, DROP COLUMN is_active, DROP COLUMN raw_payload');

        // Remover colunas adicionadas em waze_alert
        $this->addSql('ALTER TABLE waze_alert DROP FOREIGN KEY FK_WAZE_ALERT_PARTNER');
        $this->addSql('ALTER TABLE waze_alert DROP FOREIGN KEY FK_WAZE_ALERT_FEED');
        $this->addSql('ALTER TABLE waze_alert DROP FOREIGN KEY FK_WAZE_ALERT_COLLECTION');
        $this->addSql('ALTER TABLE waze_alert DROP COLUMN partner_id, DROP COLUMN waze_feed_id, DROP COLUMN last_seen_collection_id, DROP COLUMN external_uuid, DROP COLUMN dedup_key, DROP COLUMN semantic_cluster_key, DROP COLUMN latitude, DROP COLUMN longitude, DROP COLUMN geohash, DROP COLUMN street, DROP COLUMN street_normalized, DROP COLUMN city, DROP COLUMN country, DROP COLUMN road_type, DROP COLUMN description, DROP COLUMN confidence, DROP COLUMN reliability, DROP COLUMN report_rating, DROP COLUMN thumbs_up, DROP COLUMN magvar, DROP COLUMN reported_at, DROP COLUMN first_seen_at, DROP COLUMN last_seen_at, DROP COLUMN missing_since_at, DROP COLUMN deactivated_at, DROP COLUMN is_active, DROP COLUMN raw_payload');
    }

    private function getExistingColumns(string $table): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        )->fetchAllAssociative();

        return array_column($rows, 'COLUMN_NAME');
    }
}
