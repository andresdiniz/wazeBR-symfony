<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260001000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id           INT AUTO_INCREMENT NOT NULL,
                email        VARCHAR(180)  NOT NULL,
                name         VARCHAR(120)  NOT NULL,
                roles        JSON          NOT NULL,
                password     VARCHAR(255)  NOT NULL,
                is_active    TINYINT(1)    NOT NULL DEFAULT 1,
                created_at   DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                last_login_at DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');
    }
}
