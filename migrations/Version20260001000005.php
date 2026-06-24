<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260001000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela activity_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE activity_log (
                id          INT AUTO_INCREMENT NOT NULL,
                user_id     INT          DEFAULT NULL,
                action      VARCHAR(60)  NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                context     JSON         DEFAULT NULL,
                ip_address  VARCHAR(45)  DEFAULT NULL,
                created_at  DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_activity_log_user (user_id),
                INDEX idx_action_created (action, created_at),
                CONSTRAINT FK_activity_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE activity_log');
    }
}
