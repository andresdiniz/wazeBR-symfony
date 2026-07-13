<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713_CifsEvent extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabelas cifs_event_type e cifs_event para o feed CIFS do Waze';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cifs_event_type (
                id          INT AUTO_INCREMENT NOT NULL,
                type        VARCHAR(60)  NOT NULL,
                subtype     VARCHAR(80)  DEFAULT NULL,
                locale      VARCHAR(5)   NOT NULL,
                label       VARCHAR(120) NOT NULL,
                description LONGTEXT     DEFAULT NULL,
                UNIQUE INDEX uniq_type_subtype_locale (type, subtype, locale),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE cifs_event (
                id            INT AUTO_INCREMENT NOT NULL,
                partner_id    INT DEFAULT NULL,
                external_id   VARCHAR(64)  NOT NULL,
                type          VARCHAR(30)  NOT NULL,
                subtype       VARCHAR(80)  DEFAULT NULL,
                polyline      LONGTEXT     NOT NULL,
                street        VARCHAR(150) NOT NULL,
                direction     VARCHAR(20)  DEFAULT NULL,
                description   VARCHAR(40)  DEFAULT NULL,
                start_time    DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                end_time      DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                creation_time DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                update_time   DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                active        TINYINT(1)   NOT NULL DEFAULT 1,
                UNIQUE INDEX UNIQ_CIFS_EXT (external_id),
                INDEX IDX_CIFS_PARTNER (partner_id),
                INDEX IDX_CIFS_TYPE (type),
                INDEX IDX_CIFS_ACTIVE_START (active, start_time),
                PRIMARY KEY(id),
                CONSTRAINT FK_CIFS_PARTNER FOREIGN KEY (partner_id)
                    REFERENCES partner (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cifs_event');
        $this->addSql('DROP TABLE IF EXISTS cifs_event_type');
    }
}
