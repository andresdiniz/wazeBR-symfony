<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260001000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela waze_alerts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE waze_alerts (
                id            INT AUTO_INCREMENT NOT NULL,
                waze_id       VARCHAR(50)  NOT NULL,
                type          VARCHAR(60)  NOT NULL,
                subtype       VARCHAR(60)  DEFAULT NULL,
                latitude      DECIMAL(10,7) NOT NULL,
                longitude     DECIMAL(10,7) NOT NULL,
                street        VARCHAR(120) DEFAULT NULL,
                city          VARCHAR(80)  DEFAULT NULL,
                country       VARCHAR(10)  DEFAULT NULL,
                reliability   INT          DEFAULT NULL,
                confidence    INT          DEFAULT NULL,
                report_rating INT          DEFAULT NULL,
                pub_millis    BIGINT       NOT NULL,
                created_at    DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_type_city (type, city),
                INDEX idx_pubmillis (pub_millis),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE waze_alerts');
    }
}
