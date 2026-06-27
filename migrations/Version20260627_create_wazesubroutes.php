<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627_create_wazesubroutes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela wazesubroutes com FK para waze_routes';
    }

    public function up(Schema $schema): void
    {
        // Garante idempotência: só cria se não existir
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wazesubroutes (
                id           INT AUTO_INCREMENT NOT NULL,
                route_id     INT NOT NULL,
                from_name    VARCHAR(255)  DEFAULT NULL,
                to_name      VARCHAR(255)  DEFAULT NULL,
                time         INT           DEFAULT NULL COMMENT 'Tempo atual em segundos',
                historic_time INT          DEFAULT NULL COMMENT 'Tempo histórico em segundos',
                length       INT           DEFAULT NULL COMMENT 'Comprimento em metros',
                jam_level    INT           DEFAULT NULL COMMENT '0=livre, 4=parado',
                line         LONGTEXT      DEFAULT NULL COMMENT 'JSON [{x,y}]' COLLATE `utf8mb4_bin`,
                bbox         LONGTEXT      DEFAULT NULL COMMENT 'JSON {minX,minY,maxX,maxY}' COLLATE `utf8mb4_bin`,
                sort_order   INT           DEFAULT NULL COMMENT 'Posição ordinal (0-based)',
                PRIMARY KEY (id),
                INDEX IDX_wazesubroutes_route (route_id),
                CONSTRAINT FK_wazesubroutes_route
                    FOREIGN KEY (route_id)
                    REFERENCES waze_routes (id)
                    ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS wazesubroutes');
    }
}
