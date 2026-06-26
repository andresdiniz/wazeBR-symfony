<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sincroniza a tabela waze_routes com a entidade WazeRoute atual.
 *
 * Usa ADD COLUMN IF NOT EXISTS (MySQL 8+ / MariaDB) para ser idempotente:
 * roda sem erro mesmo que alguma coluna já exista.
 *
 * Colunas garantidas por esta migration:
 *   waze_id, from_name, to_name, type, time, historic_time,
 *   jam_level, line, bbox, alternate_route, is_active, description,
 *   coordinates, collected_at
 */
final class Version20260625_waze_routes_sync extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sincroniza waze_routes com entidade WazeRoute (ADD COLUMN IF NOT EXISTS)';
    }

    public function up(Schema $schema): void
    {
        // Adiciona todas as colunas que podem estar faltando.
        // IF NOT EXISTS garante idempotência — sem erro se já existir.
        $this->addSql(<<<'SQL'
            ALTER TABLE waze_routes
                ADD COLUMN IF NOT EXISTS waze_id         VARCHAR(50)      DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS from_name       VARCHAR(255)     DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS to_name         VARCHAR(255)     DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS type            VARCHAR(30)      DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS time            INT              DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS historic_time   INT              DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS jam_level       INT              DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS `line`          LONGTEXT         DEFAULT NULL COMMENT '(DC2Type:json)',
                ADD COLUMN IF NOT EXISTS bbox            LONGTEXT         DEFAULT NULL COMMENT '(DC2Type:json)',
                ADD COLUMN IF NOT EXISTS alternate_route LONGTEXT         DEFAULT NULL COMMENT '(DC2Type:json)',
                ADD COLUMN IF NOT EXISTS is_active       TINYINT(1)       DEFAULT 1,
                ADD COLUMN IF NOT EXISTS description     VARCHAR(1000)    DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS coordinates     LONGTEXT         DEFAULT NULL COMMENT '(DC2Type:json)',
                ADD COLUMN IF NOT EXISTS collected_at    DATETIME         DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE waze_routes
                DROP COLUMN IF EXISTS waze_id,
                DROP COLUMN IF EXISTS from_name,
                DROP COLUMN IF EXISTS to_name,
                DROP COLUMN IF EXISTS type,
                DROP COLUMN IF EXISTS time,
                DROP COLUMN IF EXISTS historic_time,
                DROP COLUMN IF EXISTS jam_level,
                DROP COLUMN IF EXISTS `line`,
                DROP COLUMN IF EXISTS bbox,
                DROP COLUMN IF EXISTS alternate_route,
                DROP COLUMN IF EXISTS is_active,
                DROP COLUMN IF EXISTS description,
                DROP COLUMN IF EXISTS coordinates,
                DROP COLUMN IF EXISTS collected_at
            SQL);
    }
}
