<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912082100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create partner API links table for alerts and traffic endpoints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE partner_api_link (
                id INT AUTO_INCREMENT NOT NULL,
                partner_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(2048) NOT NULL,
                active TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                INDEX IDX_PARTNER_API_LINK_PARTNER (partner_id),
                INDEX IDX_PARTNER_API_LINK_PARTNER_TYPE_ACTIVE (partner_id, type, active),
                PRIMARY KEY(id),
                CONSTRAINT FK_PARTNER_API_LINK_PARTNER FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE partner_api_link');
    }
}
