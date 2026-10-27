<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909230500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TVT tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS waze_tvt_route_history (id INT AUTO_INCREMENT NOT NULL, route_id VARCHAR(255) NOT NULL, observed_at DATETIME NOT NULL, travel_time_seconds INT DEFAULT NULL, speed_kmh DECIMAL(8, 2) DEFAULT NULL, delay_seconds INT DEFAULT NULL, length_meters INT DEFAULT NULL, status VARCHAR(40) DEFAULT NULL, raw_metrics JSON DEFAULT NULL, INDEX IDX_route_id (route_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waze_tvt_route_history');
    }
}
