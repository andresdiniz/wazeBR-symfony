<?php

declare(strict_types=1);

namespace App\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration para refatorar estrutura de rotas - separar estado atual de historico
 */
final class Version20260822220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refatorar estrutura waze_tvt_routes - separar estado atual de historico';
    }

    public function up(Schema $schema): void
    {
        // Criar tabela de historico
        $this->addSql('
            CREATE TABLE waze_tvt_route_history (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                route_id INT NOT NULL,
                jam_level INT DEFAULT NULL,
                length_meters INT DEFAULT NULL,
                delay_seconds INT DEFAULT NULL,
                speed_kmh DECIMAL(5,2) DEFAULT NULL,
                collected_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                INDEX idx_history_route (route_id),
                INDEX idx_history_collected (collected_at)
            ) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_unicode_ci` ENGINE = InnoDB
        ');

        // Criar tabela de coordenadas
        $this->addSql('
            CREATE TABLE waze_tvt_route_history_coords (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                history_id BIGINT NOT NULL,
                latitude DECIMAL(10,8) DEFAULT NULL,
                longitude DECIMAL(11,8) DEFAULT NULL,
                order_index INT DEFAULT NULL,
                INDEX idx_coords_history (history_id)
            ) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_unicode_ci` ENGINE = InnoDB
        ');

        // Adicionar FK para waze_tvt_routes
        $this->addSql('
            ALTER TABLE waze_tvt_route_history
            ADD CONSTRAINT FK_ROUTE_HISTORY
            FOREIGN KEY (route_id) REFERENCES waze_tvt_routes(id)
            ON DELETE CASCADE
        ');

        // Adicionar FK para waze_tvt_route_history
        $this->addSql('
            ALTER TABLE waze_tvt_route_history_coords
            ADD CONSTRAINT FK_COORDS_HISTORY
            FOREIGN KEY (history_id) REFERENCES waze_tvt_route_history(id)
            ON DELETE CASCADE
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_tvt_route_history_coords DROP FOREIGN KEY FK_COORDS_HISTORY');
        $this->addSql('ALTER TABLE waze_tvt_route_history DROP FOREIGN KEY FK_ROUTE_HISTORY');
        $this->addSql('DROP TABLE waze_tvt_route_history_coords');
        $this->addSql('DROP TABLE waze_tvt_route_history');
    }
}
