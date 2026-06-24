<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260001000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela waze_traffic_jams';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE waze_traffic_jams (
                id         INT AUTO_INCREMENT NOT NULL,
                waze_id    VARCHAR(50)   NOT NULL,
                street     VARCHAR(120)  DEFAULT NULL,
                city       VARCHAR(80)   DEFAULT NULL,
                level      INT           DEFAULT NULL,
                speed_kmh  DECIMAL(8,2)  DEFAULT NULL,
                length     DECIMAL(8,2)  DEFAULT NULL,
                delay      INT           DEFAULT NULL,
                line       JSON          DEFAULT NULL,
                pub_millis BIGINT        NOT NULL,
                created_at DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_city_level (city, level),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE waze_traffic_jams');
    }
}
