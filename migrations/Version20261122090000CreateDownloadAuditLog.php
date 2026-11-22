<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122090000CreateDownloadAuditLog extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates audit entries for authenticated file downloads.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE download_audit_log (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, partner_id INT DEFAULT NULL, route VARCHAR(255) NOT NULL, filename VARCHAR(255) NOT NULL, content_type VARCHAR(127) DEFAULT NULL, client_ip_hash VARCHAR(64) DEFAULT NULL, requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_download_audit_requested_at (requested_at), INDEX idx_download_audit_user_at (user_id, requested_at), INDEX idx_download_audit_partner_at (partner_id, requested_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE download_audit_log ADD CONSTRAINT fk_download_audit_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE download_audit_log ADD CONSTRAINT fk_download_audit_partner FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE download_audit_log');
    }
}
