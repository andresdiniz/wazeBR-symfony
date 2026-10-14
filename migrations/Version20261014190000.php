<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela de previsão de congestionamento por rota TVT, dia da semana e faixa horária.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE waze_tvt_route_forecast (
                id INT AUTO_INCREMENT NOT NULL,
                route_id INT NOT NULL,
                forecast_at DATETIME NOT NULL,
                weekday SMALLINT NOT NULL,
                hour SMALLINT NOT NULL,
                slot_minutes SMALLINT NOT NULL,
                avg_time DOUBLE PRECISION DEFAULT NULL,
                avg_historic_time DOUBLE PRECISION DEFAULT NULL,
                avg_jam_level DOUBLE PRECISION DEFAULT NULL,
                samples INT NOT NULL,
                risk_score DOUBLE PRECISION NOT NULL,
                risk_level VARCHAR(10) NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_tvt_route_forecast_slot (route_id, forecast_at),
                INDEX idx_tvt_route_forecast_lookup (forecast_at, risk_level),
                INDEX idx_tvt_route_forecast_route (route_id, forecast_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(
            'ALTER TABLE waze_tvt_route_forecast '
            . 'ADD CONSTRAINT FK_TVT_ROUTE_FORECAST_ROUTE '
            . 'FOREIGN KEY (route_id) REFERENCES waze_tvt_route (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE waze_tvt_route_forecast');
    }
}
