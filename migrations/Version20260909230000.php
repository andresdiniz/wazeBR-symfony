<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create TVT tables with correct schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_feed (id INT AUTO_INCREMENT NOT NULL, partner_id INT NOT NULL, endpoint_url VARCHAR(255) DEFAULT NULL, feed_uuid VARCHAR(255) NOT NULL, feed_id INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_741E8694D0DB441 (partner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_feed_collection (id INT AUTO_INCREMENT NOT NULL, waze_feed_id INT NOT NULL, status VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_9A8B3E6D0DB441 (waze_feed_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_partner (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, api_token VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_tvt_route (id INT AUTO_INCREMENT NOT NULL, partner_id INT NOT NULL, waze_feed_id INT NOT NULL, external_route_id VARCHAR(255) NOT NULL, label VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_741E8694D0DB441 (partner_id, waze_feed_id, external_route_id), INDEX IDX_741E8694D0DB441 (partner_id), INDEX IDX_741E8694D0DB441 (waze_feed_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_tvt_route_definition (id INT AUTO_INCREMENT NOT NULL, route_id VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, bbox LONGTEXT DEFAULT NULL, line LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_tvt_route_history (id INT AUTO_INCREMENT NOT NULL, route_id VARCHAR(255) NOT NULL, observed_at DATETIME NOT NULL, travel_time_seconds INT DEFAULT NULL, speed_kmh DECIMAL(8, 2) DEFAULT NULL, delay_seconds INT DEFAULT NULL, length_meters INT DEFAULT NULL, status VARCHAR(40) DEFAULT NULL, raw_metrics JSON DEFAULT NULL, INDEX IDX_route_id (route_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE waze_feed ADD CONSTRAINT FK_741E8694D0DB441 FOREIGN KEY (partner_id) REFERENCES waze_partner (id)');
        $this->addSql('ALTER TABLE waze_feed_collection ADD CONSTRAINT FK_9A8B3E6D0DB441 FOREIGN KEY (waze_feed_id) REFERENCES waze_feed (id)');
        $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_741E8694D0DB441 FOREIGN KEY (partner_id) REFERENCES waze_partner (id)');
        $this->addSql('ALTER TABLE waze_tvt_route ADD CONSTRAINT FK_741E8694D0DB441 FOREIGN KEY (waze_feed_id) REFERENCES waze_feed (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waze_tvt_route_history');
        $this->addSql('DROP TABLE IF EXISTS waze_tvt_route_definition');
        $this->addSql('DROP TABLE IF EXISTS waze_tvt_route');
        $this->addSql('DROP TABLE IF EXISTS waze_feed_collection');
        $this->addSql('DROP TABLE IF EXISTS waze_feed');
        $this->addSql('DROP TABLE IF EXISTS waze_partner');
    }
}
