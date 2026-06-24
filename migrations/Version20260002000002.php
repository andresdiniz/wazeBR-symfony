<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260002000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabelas waze_routes, waze_route_links, monitored_cities, monitored_links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE waze_routes (
                id          INT AUTO_INCREMENT NOT NULL,
                partner_id  INT NOT NULL,
                name        VARCHAR(80)  NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                coordinates JSON         NOT NULL,
                is_active   TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_routes_partner (partner_id),
                CONSTRAINT FK_routes_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE waze_route_links (
                id          INT AUTO_INCREMENT NOT NULL,
                route_id    INT NOT NULL,
                name        VARCHAR(80) NOT NULL,
                coordinates JSON        NOT NULL,
                sort_order  INT         DEFAULT NULL,
                created_at  DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_route_links_route (route_id),
                CONSTRAINT FK_route_links_route FOREIGN KEY (route_id) REFERENCES waze_routes (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE monitored_cities (
                id         INT AUTO_INCREMENT NOT NULL,
                partner_id INT NOT NULL,
                city       VARCHAR(80) NOT NULL,
                state      VARCHAR(2)  NOT NULL,
                country    VARCHAR(10) DEFAULT 'BR',
                is_active  TINYINT(1)  NOT NULL DEFAULT 1,
                created_at DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UQ_city_partner (partner_id, city, state),
                CONSTRAINT FK_cities_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE monitored_links (
                id         INT AUTO_INCREMENT NOT NULL,
                partner_id INT NOT NULL,
                name       VARCHAR(120) NOT NULL,
                url        VARCHAR(255) NOT NULL,
                type       VARCHAR(40)  NOT NULL DEFAULT 'generic',
                is_active  TINYINT(1)   NOT NULL DEFAULT 1,
                created_at DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_links_partner (partner_id),
                CONSTRAINT FK_links_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE monitored_links');
        $this->addSql('DROP TABLE monitored_cities');
        $this->addSql('DROP TABLE waze_route_links');
        $this->addSql('DROP TABLE waze_routes');
    }
}
