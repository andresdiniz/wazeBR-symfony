<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: cria tabelas waze_tvt_snapshots e waze_tvt_routes.
 *
 * Substitui a entidade WazeTrafficJam (que esperava 'jams' no JSON)
 * pela arquitetura correta para o feed TVT real do Waze (routes/subRoutes).
 */
final class Version20260625_waze_tvt extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria waze_tvt_snapshots e waze_tvt_routes para o feed TVT real do Waze';
    }

    public function up(Schema $schema): void
    {
        // --- Snapshot do feed TVT ---
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS waze_tvt_snapshots (
                id              INT AUTO_INCREMENT NOT NULL,
                partner_id      INT NOT NULL,
                source_link_id  INT NOT NULL,
                update_time     BIGINT          DEFAULT NULL COMMENT 'updateTime do JSON em ms Unix',
                feed_name       VARCHAR(120)    DEFAULT NULL,
                area_name       VARCHAR(120)    DEFAULT NULL,
                broadcaster_id  VARCHAR(80)     DEFAULT NULL,
                is_metric       TINYINT(1)      NOT NULL DEFAULT 1,
                bbox            JSON            DEFAULT NULL COMMENT '{minX,maxX,minY,maxY}',
                users_on_jams   JSON            NOT NULL COMMENT '[{jamLevel,wazersCount}]',
                length_of_jams  JSON            NOT NULL COMMENT '[{jamLevel,jamLength}]',
                irregularities  JSON            NOT NULL,
                route_count     INT             NOT NULL DEFAULT 0,
                collected_at    DATETIME        NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_tvt_partner     (partner_id),
                INDEX idx_tvt_link        (source_link_id),
                INDEX idx_tvt_collected_at (collected_at),
                CONSTRAINT fk_tvt_snap_partner   FOREIGN KEY (partner_id)     REFERENCES partners (id) ON DELETE CASCADE,
                CONSTRAINT fk_tvt_snap_link      FOREIGN KEY (source_link_id) REFERENCES monitored_links (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        // --- Rotas individuais por snapshot ---
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS waze_tvt_routes (
                id              INT AUTO_INCREMENT NOT NULL,
                snapshot_id     INT NOT NULL,
                waze_route_id   VARCHAR(40)     DEFAULT NULL  COMMENT 'ID da rota no Waze (string numerica)',
                is_sub_route    TINYINT(1)      NOT NULL DEFAULT 0,
                parent_waze_id  VARCHAR(40)     DEFAULT NULL  COMMENT 'wazeRouteId da rota pai',
                name            VARCHAR(160)    DEFAULT NULL,
                type            VARCHAR(20)     DEFAULT NULL  COMMENT 'STATIC | DYNAMIC',
                from_name       VARCHAR(200)    DEFAULT NULL,
                to_name         VARCHAR(200)    DEFAULT NULL,
                length          INT             DEFAULT NULL  COMMENT 'metros',
                time            INT             DEFAULT NULL  COMMENT 'segundos (tempo atual)',
                historic_time   INT             DEFAULT NULL  COMMENT 'segundos (tempo historico)',
                jam_level       TINYINT         DEFAULT NULL  COMMENT '0=livre 1=lento 2=moderado 3=pesado 4=muito pesado 5=parado',
                line            JSON            NOT NULL      COMMENT '[{x:lon, y:lat}]',
                bbox            JSON            DEFAULT NULL,
                sub_routes_raw  JSON            NOT NULL      COMMENT 'subRoutes brutas do JSON',
                PRIMARY KEY (id),
                INDEX idx_tvt_route_snapshot  (snapshot_id),
                INDEX idx_tvt_route_waze_id   (waze_route_id),
                INDEX idx_tvt_route_jam_level  (jam_level),
                CONSTRAINT fk_tvt_route_snapshot FOREIGN KEY (snapshot_id) REFERENCES waze_tvt_snapshots (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waze_tvt_routes');
        $this->addSql('DROP TABLE IF EXISTS waze_tvt_snapshots');
    }
}
