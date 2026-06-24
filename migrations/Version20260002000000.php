<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260002000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela partners (tenants SaaS)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE partners (
                id             INT AUTO_INCREMENT NOT NULL,
                slug           VARCHAR(80)  NOT NULL,
                name           VARCHAR(120) NOT NULL,
                email          VARCHAR(180) NOT NULL,
                api_token      VARCHAR(64)  NOT NULL,
                bbox           VARCHAR(100) DEFAULT NULL,
                cemaden_states JSON         NOT NULL,
                is_active      TINYINT(1)   NOT NULL DEFAULT 1,
                created_at     DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_partners_slug  (slug),
                UNIQUE INDEX UNIQ_partners_email (email),
                UNIQUE INDEX UNIQ_partners_token (api_token),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE partners');
    }
}
