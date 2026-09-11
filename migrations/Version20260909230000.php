<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create TVT tables with partner references and BIGINT feed keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_feed (id BIGINT AUTO_INCREMENT NOT NULL, partner_id INT NOT NULL, feed_type VARCHAR(50) DEFAULT NULL, provider VARCHAR(80) DEFAULT NULL, external_partner_id VARCHAR(80) DEFAULT NULL, feed_uuid VARCHAR(255) NOT NULL, feed_id INT DEFAULT NULL, external_route_id VARCHAR(255) DEFAULT NULL, endpoint_url VARCHAR(255) DEFAULT NULL, type VARCHAR(50) DEFAULT NULL, active TINYINT(1) DEFAULT NULL, label VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) DEFAULT NULL, last_success_at DATETIME DEFAULT NULL, last_error_at DATETIME DEFAULT NULL, last_error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_WAZE_FEED_PARTNER (partner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_feed_collection (id BIGINT AUTO_INCREMENT NOT NULL, waze_feed_id BIGINT NOT NULL, status VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_WAZE_FEED_COLLECTION_FEED (waze_feed_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_tvt_route_definition (id BIGINT AUTO_INCREMENT NOT NULL, route_id VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, bbox LONGTEXT DEFAULT NULL, line LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_tvt_route_history (id BIGINT AUTO_INCREMENT NOT NULL, route_id VARCHAR(255) NOT NULL, observed_at DATETIME NOT NULL, travel_time_seconds INT DEFAULT NULL, speed_kmh DECIMAL(8, 2) DEFAULT NULL, delay_seconds INT DEFAULT NULL, length_meters INT DEFAULT NULL, status VARCHAR(40) DEFAULT NULL, raw_metrics JSON DEFAULT NULL, INDEX IDX_WAZE_TVT_HISTORY_ROUTE (route_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_tvt_route (id INT AUTO_INCREMENT NOT NULL, partner_id INT NOT NULL, waze_feed_id BIGINT NOT NULL, external_route_id VARCHAR(255) NOT NULL, label VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_WAZE_TVT_ROUTE_KEYS (partner_id, waze_feed_id, external_route_id), INDEX IDX_WAZE_TVT_ROUTE_PARTNER (partner_id), INDEX IDX_WAZE_TVT_ROUTE_FEED (waze_feed_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addForeignKeyIfMissing('waze_feed', 'FK_WAZE_FEED_PARTNER', 'partner_id', 'partner');
        $this->addForeignKeyIfMissing('waze_feed_collection', 'FK_WAZE_FEED_COLLECTION_FEED', 'waze_feed_id', 'waze_feed');
        $this->addForeignKeyIfMissing('waze_tvt_route', 'FK_WAZE_TVT_ROUTE_PARTNER', 'partner_id', 'partner');
        $this->addForeignKeyIfMissing('waze_tvt_route', 'FK_WAZE_TVT_ROUTE_FEED', 'waze_feed_id', 'waze_feed');
    }

    private function addForeignKeyIfMissing(string $table, string $constraint, string $column, string $referencedTable): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
            [$table, $constraint]
        );

        if ((int) $exists === 0) {
            $this->addSql(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`)', $table, $constraint, $column, $referencedTable));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waze_tvt_route_history');
        $this->addSql('DROP TABLE IF EXISTS waze_tvt_route_definition');
        $this->addSql('DROP TABLE IF EXISTS waze_tvt_route');
        $this->addSql('DROP TABLE IF EXISTS waze_feed_collection');
        $this->addSql('DROP TABLE IF EXISTS waze_feed');
    }
}
