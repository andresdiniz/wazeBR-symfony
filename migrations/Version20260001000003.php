<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260001000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela cemaden_data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cemaden_data (
                id               INT AUTO_INCREMENT NOT NULL,
                station_code     VARCHAR(80)  NOT NULL,
                station_name     VARCHAR(120) NOT NULL,
                municipality     VARCHAR(80)  NOT NULL,
                state            VARCHAR(2)   NOT NULL,
                latitude         DECIMAL(10,7) NOT NULL,
                longitude        DECIMAL(10,7) NOT NULL,
                accumulated_rain DECIMAL(8,2) DEFAULT NULL,
                water_level      DECIMAL(8,2) DEFAULT NULL,
                alert_level      VARCHAR(20)  DEFAULT NULL,
                measured_at      DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at       DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cemaden_data');
    }
}
