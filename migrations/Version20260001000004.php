<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260001000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
                id         INT AUTO_INCREMENT NOT NULL,
                user_id    INT          NOT NULL,
                type       VARCHAR(40)  NOT NULL,
                title      VARCHAR(255) NOT NULL,
                body       LONGTEXT     DEFAULT NULL,
                is_read    TINYINT(1)   NOT NULL DEFAULT 0,
                payload    JSON         DEFAULT NULL,
                created_at DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_notifications_user (user_id),
                CONSTRAINT FK_notifications_user FOREIGN KEY (user_id) REFERENCES users (id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notifications');
    }
}
