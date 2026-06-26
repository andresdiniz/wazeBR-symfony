<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626_route_snapshots extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela waze_route_snapshots para histórico de buscas por rota';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS waze_route_snapshots (
                id            INT NOT NULL AUTO_INCREMENT,
                route_id      INT NOT NULL,
                time          INT          DEFAULT NULL,
                historic_time INT          DEFAULT NULL,
                length        INT          DEFAULT NULL,
                jam_level     INT          DEFAULT NULL,
                collected_at  DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_route_snapshot_route_time (route_id, collected_at),
                CONSTRAINT FK_route_snapshot_route
                    FOREIGN KEY (route_id)
                    REFERENCES waze_routes (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS waze_route_snapshots');
    }
}
