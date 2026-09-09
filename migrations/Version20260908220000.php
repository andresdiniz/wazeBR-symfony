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
        // ── 1. waze_feed ─────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE waze_feed (
                id                  BIGINT AUTO_INCREMENT NOT NULL,
                partner_id          BIGINT NOT NULL,
                feed_type           VARCHAR(20) NOT NULL,
                provider            VARCHAR(30) NOT NULL DEFAULT \'WAZE\',
                external_partner_id VARCHAR(80) DEFAULT NULL,
                feed_uuid           VARCHAR(50) NOT NULL,
                external_route_id   VARCHAR(80) DEFAULT NULL,
                endpoint_url        TEXT NOT NULL,
                label               VARCHAR(150) DEFAULT NULL,
                is_active           TINYINT(1) NOT NULL DEFAULT 1,
                last_success_at     DATETIME DEFAULT NULL,
                last_error_at       DATETIME DEFAULT NULL,
                last_error_message  TEXT DEFAULT NULL,
                created_at          DATETIME NOT NULL,
                updated_at          DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX IDX_WAZE_FEED_PARTNER (partner_id),
                INDEX IDX_WAZE_FEED_TYPE_ACTIVE (feed_type, is_active),
                UNIQUE KEY UQ_WAZE_FEED_UNIQUE (partner_id, feed_type, feed_uuid, external_route_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            ALTER TABLE waze_feed
            ADD CONSTRAINT FK_WAZE_FEED_PARTNER FOREIGN KEY (partner_id) REFERENCES partner (id)
        ');

        // ── 2. waze_feed_collection ──────────────────────────────────────────
        $this->addSql('
            CREATE TABLE waze_feed_collection (
                id              BIGINT AUTO_INCREMENT NOT NULL,
                waze_feed_id    BIGINT NOT NULL,
                started_at      DATETIME NOT NULL,
                finished_at     DATETIME DEFAULT NULL,
                status          VARCHAR(20) NOT NULL DEFAULT \'RUNNING\',
                http_status     SMALLINT DEFAULT NULL,
                alerts_received INT NOT NULL DEFAULT 0,
                jams_received   INT NOT NULL DEFAULT 0,
                routes_received INT NOT NULL DEFAULT 0,
                payload_hash    VARCHAR(64) DEFAULT NULL,
                error_message   TEXT DEFAULT NULL,
                created_at      DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX IDX_WAZE_COLLECTION_FEED (waze_feed_id, started_at DESC),
                INDEX IDX_WAZE_COLLECTION_STATUS (status, started_at DESC)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            ALTER TABLE waze_feed_collection
            ADD CONSTRAINT FK_WAZE_COLLECTION_FEED FOREIGN KEY (waze_feed_id) REFERENCES waze_feed (id)
        ');

        // ── 3. waze_alert — novos campos ─────────────────────────────────────
        $this->addSql('ALTER TABLE waze_alert ADD partner_id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD waze_feed_id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD last_seen_collection_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD external_uuid VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD dedup_key VARCHAR(64) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE waze_alert ADD semantic_cluster_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD latitude DECIMAL(10,7) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE waze_alert ADD longitude DECIMAL(10,7) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE waze_alert ADD geohash VARCHAR(12) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE waze_alert ADD street VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD street_normalized VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD city VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD country VARCHAR(2) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD road_type SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD confidence SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD reliability SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD report_rating SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD thumbs_up INT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD magvar SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD reported_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD first_seen_at DATETIME NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE waze_alert ADD last_seen_at DATETIME NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE waze_alert ADD missing_since_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD deactivated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alert ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE waze_alert ADD raw_payload JSON DEFAULT NULL');

        $this->addSql('ALTER TABLE waze_alert ADD CONSTRAINT FK_WAZE_ALERT_PARTNER    FOREIGN KEY (partner_id)              REFERENCES partner (id)');
        $this->addSql('ALTER TABLE waze_alert ADD CONSTRAINT FK_WAZE_ALERT_FEED       FOREIGN KEY (waze_feed_id)            REFERENCES waze_feed (id)');
        $this->addSql('ALTER TABLE waze_alert ADD CONSTRAINT FK_WAZE_ALERT_COLLECTION FOREIGN KEY (last_seen_collection_id) REFERENCES waze_feed_collection (id)');

        $this->addSql('CREATE INDEX IDX_WAZE_ALERT_EXTERNAL    ON waze_alert (partner_id, waze_feed_id, external_uuid)');
        $this->addSql('CREATE INDEX IDX_WAZE_ALERT_DEDUP       ON waze_alert (partner_id, dedup_key)');
        $this->addSql('CREATE INDEX IDX_WAZE_ALERT_ACTIVE_SEEN ON waze_alert (partner_id, is_active, last_seen_at DESC)');

        // ── 4. waze_traffic_jam — novos campos ───────────────────────────────
        $this->addSql('ALTER TABLE waze_traffic_jam ADD partner_id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD waze_feed_id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD last_seen_collection_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD external_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD external_uuid VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD dedup_key VARCHAR(64) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD street VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD street_normalized VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD city VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD country VARCHAR(2) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD road_type SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD start_latitude DECIMAL(10,7) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD start_longitude DECIMAL(10,7) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD end_latitude DECIMAL(10,7) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD end_longitude DECIMAL(10,7) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD geometry_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD geometry JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD length_meters INT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD speed_kmh DECIMAL(8,2) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD speed_mps DECIMAL(8,2) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD delay_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD level SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD turn_type VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD blocking_alert_uuid VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD published_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD first_seen_at DATETIME NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD last_seen_at DATETIME NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD missing_since_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD deactivated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD raw_payload JSON DEFAULT NULL');

        $this->addSql('ALTER TABLE waze_traffic_jam ADD CONSTRAINT FK_WAZE_JAM_PARTNER    FOREIGN KEY (partner_id)              REFERENCES partner (id)');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD CONSTRAINT FK_WAZE_JAM_FEED       FOREIGN KEY (waze_feed_id)            REFERENCES waze_feed (id)');
        $this->addSql('ALTER TABLE waze_traffic_jam ADD CONSTRAINT FK_WAZE_JAM_COLLECTION FOREIGN KEY (last_seen_collection_id) REFERENCES waze_feed_collection (id)');

        $this->addSql('CREATE INDEX IDX_WAZE_JAM_EXTERNAL    ON waze_traffic_jam (partner_id, waze_feed_id, external_uuid)');
        $this->addSql('CREATE INDEX IDX_WAZE_JAM_DEDUP       ON waze_traffic_jam (partner_id, dedup_key)');
        $this->addSql('CREATE INDEX IDX_WAZE_JAM_ACTIVE_SEEN ON waze_traffic_jam (partner_id, is_active, last_seen_at DESC)');

        // ── 5. waze_tvt_route — novos campos ─────────────────────────────────
        $this->addSql('ALTER TABLE waze_tvt_route ADD partner_id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE waze_tvt_route ADD waze_feed_id BIGINT NOT NULL');
        $this->addSql('ALTER TABLE waze_tvt_route ADD current_definition_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_tvt_route ADD external_route_id VARCHAR(80) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE waze_tvt_route ADD external_uuid VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_tvt_route ADD label VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_tvt_route ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE waze_tvt_route ADD first_seen_at DATETIME NOT NULL DEFAULT NOW()');
        $this->addSql('ALTER TABLE waze_tvt_route ADD last_seen_at DATETIME NOT NULL DEFAULT NOW()');

        $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_WAZE_TVT_ROUTE_PARTNER     FOREIGN KEY (partner_id)            REFERENCES partner (id)');
        $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_WAZE_TVT_ROUTE_FEED        FOREIGN KEY (waze_feed_id)          REFERENCES waze_feed (id)');
        $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_WAZE_TVT_ROUTE_CURRENT_DEF FOREIGN KEY (current_definition_id) REFERENCES waze_tvt_route_definition (id)');

        $this->addSql('CREATE INDEX IDX_WAZE_TVT_ROUTE_EXTERNAL ON waze_tvt_route (partner_id, waze_feed_id, external_route_id)');
        $this->addSql('CREATE UNIQUE INDEX UQ_WAZE_TVT_ROUTE ON waze_tvt_route (waze_feed_id, external_route_id)');

        // ── 6. waze_tvt_route_definition ─────────────────────────────────────
        $this->addSql('
            CREATE TABLE waze_tvt_route_definition (
                id                BIGINT AUTO_INCREMENT NOT NULL,
                waze_tvt_route_id BIGINT NOT NULL,
                version_number    INT NOT NULL DEFAULT 1,
                definition_hash   VARCHAR(64) NOT NULL,
                name              VARCHAR(255) DEFAULT NULL,
                origin_name       VARCHAR(255) DEFAULT NULL,
                destination_name  VARCHAR(255) DEFAULT NULL,
                distance_meters   INT DEFAULT NULL,
                geometry          JSON NOT NULL,
                geometry_hash     VARCHAR(64) NOT NULL,
                segment_count     INT DEFAULT NULL,
                metadata          JSON DEFAULT NULL,
                is_current        TINYINT(1) NOT NULL DEFAULT 1,
                valid_from        DATETIME NOT NULL,
                valid_until       DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX IDX_WAZE_TVT_DEF_CURRENT (waze_tvt_route_id, is_current),
                UNIQUE KEY UQ_WAZE_TVT_DEF_HASH (waze_tvt_route_id, definition_hash)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE waze_tvt_route_definition ADD CONSTRAINT FK_WAZE_TVT_DEF_ROUTE FOREIGN KEY (waze_tvt_route_id) REFERENCES waze_tvt_route (id)');

        // ── 7. waze_tvt_route_history ─────────────────────────────────────────
        $this->addSql('
            CREATE TABLE waze_tvt_route_history (
                id                          BIGINT AUTO_INCREMENT NOT NULL,
                waze_tvt_route_id           BIGINT NOT NULL,
                waze_tvt_route_definition_id BIGINT DEFAULT NULL,
                waze_feed_collection_id     BIGINT DEFAULT NULL,
                observed_at                 DATETIME NOT NULL,
                travel_time_seconds         INT DEFAULT NULL,
                travel_time_minutes         DECIMAL(10,2) DEFAULT NULL,
                speed_kmh                   DECIMAL(8,2) DEFAULT NULL,
                delay_seconds               INT DEFAULT NULL,
                length_meters               INT DEFAULT NULL,
                status                      VARCHAR(40) DEFAULT NULL,
                raw_metrics                 JSON DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX IDX_WAZE_TVT_HISTORY_ROUTE (waze_tvt_route_id, observed_at),
                INDEX IDX_WAZE_TVT_HISTORY_COLLECTION (waze_feed_collection_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE waze_tvt_route_history ADD CONSTRAINT FK_WAZE_TVT_HIST_ROUTE      FOREIGN KEY (waze_tvt_route_id)            REFERENCES waze_tvt_route (id)');
        $this->addSql('ALTER TABLE waze_tvt_route_history ADD CONSTRAINT FK_WAZE_TVT_HIST_DEF        FOREIGN KEY (waze_tvt_route_definition_id) REFERENCES waze_tvt_route_definition (id)');
        $this->addSql('ALTER TABLE waze_tvt_route_history ADD CONSTRAINT FK_WAZE_TVT_HIST_COLLECTION FOREIGN KEY (waze_feed_collection_id)      REFERENCES waze_feed_collection (id)');
    }

    public function down(Schema $schema): void
    {
        // Remover FKs primeiro para evitar conflitos
        $this->addSql('ALTER TABLE waze_tvt_route_history DROP FOREIGN KEY FK_WAZE_TVT_HIST_ROUTE');
        $this->addSql('ALTER TABLE waze_tvt_route_history DROP FOREIGN KEY FK_WAZE_TVT_HIST_DEF');
        $this->addSql('ALTER TABLE waze_tvt_route_history DROP FOREIGN KEY FK_WAZE_TVT_HIST_COLLECTION');
        $this->addSql('DROP TABLE waze_tvt_route_history');

        $this->addSql('ALTER TABLE waze_tvt_route_definition DROP FOREIGN KEY FK_WAZE_TVT_DEF_ROUTE');
        $this->addSql('DROP TABLE waze_tvt_route_definition');

        $this->addSql('ALTER TABLE waze_tvt_route DROP FOREIGN KEY FK_WAZE_TVT_ROUTE_PARTNER');
        $this->addSql('ALTER TABLE waze_tvt_route DROP FOREIGN KEY FK_WAZE_TVT_ROUTE_FEED');
        $this->addSql('ALTER TABLE waze_tvt_route DROP FOREIGN KEY FK_WAZE_TVT_ROUTE_CURRENT_DEF');
        $this->addSql('ALTER TABLE waze_tvt_route DROP COLUMN partner_id, DROP COLUMN waze_feed_id, DROP COLUMN current_definition_id, DROP COLUMN external_route_id, DROP COLUMN external_uuid, DROP COLUMN label, DROP COLUMN is_active, DROP COLUMN first_seen_at, DROP COLUMN last_seen_at');

        $this->addSql('ALTER TABLE waze_traffic_jam DROP FOREIGN KEY FK_WAZE_JAM_PARTNER');
        $this->addSql('ALTER TABLE waze_traffic_jam DROP FOREIGN KEY FK_WAZE_JAM_FEED');
        $this->addSql('ALTER TABLE waze_traffic_jam DROP FOREIGN KEY FK_WAZE_JAM_COLLECTION');
        $this->addSql('DROP INDEX IDX_WAZE_JAM_EXTERNAL ON waze_traffic_jam');
        $this->addSql('DROP INDEX IDX_WAZE_JAM_DEDUP ON waze_traffic_jam');
        $this->addSql('DROP INDEX IDX_WAZE_JAM_ACTIVE_SEEN ON waze_traffic_jam');
        $this->addSql('ALTER TABLE waze_traffic_jam DROP COLUMN partner_id, DROP COLUMN waze_feed_id, DROP COLUMN last_seen_collection_id, DROP COLUMN external_id, DROP COLUMN external_uuid, DROP COLUMN dedup_key, DROP COLUMN street, DROP COLUMN street_normalized, DROP COLUMN city, DROP COLUMN country, DROP COLUMN road_type, DROP COLUMN start_latitude, DROP COLUMN start_longitude, DROP COLUMN end_latitude, DROP COLUMN end_longitude, DROP COLUMN geometry_hash, DROP COLUMN geometry, DROP COLUMN length_meters, DROP COLUMN speed_kmh, DROP COLUMN speed_mps, DROP COLUMN delay_seconds, DROP COLUMN level, DROP COLUMN turn_type, DROP COLUMN blocking_alert_uuid, DROP COLUMN published_at, DROP COLUMN first_seen_at, DROP COLUMN last_seen_at, DROP COLUMN missing_since_at, DROP COLUMN deactivated_at, DROP COLUMN is_active, DROP COLUMN raw_payload');

        $this->addSql('ALTER TABLE waze_alert DROP FOREIGN KEY FK_WAZE_ALERT_PARTNER');
        $this->addSql('ALTER TABLE waze_alert DROP FOREIGN KEY FK_WAZE_ALERT_FEED');
        $this->addSql('ALTER TABLE waze_alert DROP FOREIGN KEY FK_WAZE_ALERT_COLLECTION');
        $this->addSql('DROP INDEX IDX_WAZE_ALERT_EXTERNAL ON waze_alert');
        $this->addSql('DROP INDEX IDX_WAZE_ALERT_DEDUP ON waze_alert');
        $this->addSql('DROP INDEX IDX_WAZE_ALERT_ACTIVE_SEEN ON waze_alert');
        $this->addSql('ALTER TABLE waze_alert DROP COLUMN partner_id, DROP COLUMN waze_feed_id, DROP COLUMN last_seen_collection_id, DROP COLUMN external_uuid, DROP COLUMN dedup_key, DROP COLUMN semantic_cluster_key, DROP COLUMN latitude, DROP COLUMN longitude, DROP COLUMN geohash, DROP COLUMN street, DROP COLUMN street_normalized, DROP COLUMN city, DROP COLUMN country, DROP COLUMN road_type, DROP COLUMN description, DROP COLUMN confidence, DROP COLUMN reliability, DROP COLUMN report_rating, DROP COLUMN thumbs_up, DROP COLUMN magvar, DROP COLUMN reported_at, DROP COLUMN first_seen_at, DROP COLUMN last_seen_at, DROP COLUMN missing_since_at, DROP COLUMN deactivated_at, DROP COLUMN is_active, DROP COLUMN raw_payload');

        $this->addSql('ALTER TABLE waze_feed_collection DROP FOREIGN KEY FK_WAZE_COLLECTION_FEED');
        $this->addSql('DROP TABLE waze_feed_collection');

        $this->addSql('ALTER TABLE waze_feed DROP FOREIGN KEY FK_WAZE_FEED_PARTNER');
        $this->addSql('DROP TABLE waze_feed');
    }
}
