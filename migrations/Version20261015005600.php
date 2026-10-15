<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015005600 extends AbstractMigration
{
    public function getDescription(): string { return 'Immutable captured TVT forecasts and daily evaluation results (UTC)'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE waze_tvt_forecast_audit (id INT AUTO_INCREMENT NOT NULL, source_id INT NOT NULL, route_id INT NOT NULL, issued_at DATETIME NOT NULL, captured_at DATETIME NOT NULL, target_start_at DATETIME NOT NULL, target_end_at DATETIME NOT NULL, horizon_minutes DOUBLE PRECISION NOT NULL, predicted_time DOUBLE PRECISION NOT NULL, history_samples INT NOT NULL, actual_time DOUBLE PRECISION DEFAULT NULL, actual_samples INT NOT NULL DEFAULT 0, error_seconds DOUBLE PRECISION DEFAULT NULL, within_tolerance TINYINT(1) DEFAULT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', evaluated_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_forecast_audit_issue (source_id, issued_at), INDEX idx_forecast_audit_target (target_start_at, route_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE waze_tvt_forecast_audit'); }
}
