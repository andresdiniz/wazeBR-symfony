<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cria tabela system_settings (key/value) usada pelo SettingsAdminController.
 * Popula valores padrão para intervalos de coleta e cache de clima.
 */
final class Version20260626_system_settings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria system_settings e cemaden_stations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS system_settings (
                `key`   VARCHAR(80)  NOT NULL,
                `value` TEXT         NOT NULL DEFAULT '',
                PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Insere padrões (ignora se já existe)
        $defaults = [
            ['waze_alerts_interval',  '60'],
            ['waze_jams_interval',    '60'],
            ['cemaden_interval',      '900'],
            ['tvt_interval',          '120'],
            ['weatherapi_key',        ''],
            ['weather_cache_minutes', '30'],
        ];

        foreach ($defaults as [$k, $v]) {
            $this->addSql(
                "INSERT IGNORE INTO system_settings (`key`, `value`) VALUES (?, ?)",
                [$k, $v]
            );
        }

        // Tabela de estações CEMADEN administráveis
        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS cemaden_stations (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                cod_estacao  VARCHAR(30)  NOT NULL,
                nome         VARCHAR(120) NOT NULL,
                municipio    VARCHAR(80)  NOT NULL,
                uf           CHAR(2)      NOT NULL,
                partner_slug VARCHAR(40)  NOT NULL,
                is_active    TINYINT(1)   NOT NULL DEFAULT 1,
                UNIQUE KEY uk_cod_estacao (cod_estacao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cemaden_stations');
        $this->addSql('DROP TABLE IF EXISTS system_settings');
    }
}
