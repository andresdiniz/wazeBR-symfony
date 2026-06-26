<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sincroniza monitored_cities com a entidade MonitoredCity atual.
 *
 * Problema: a tabela foi criada em Version20260002000002 com coluna `city`,
 * mas a entidade MonitoredCity mapeia o campo $name -> coluna `name`.
 *
 * O que esta migration faz:
 *   1. Renomeia `city` -> `name`  (CHANGE COLUMN, preserva dados)
 *   2. Remove `country`           (a entidade nao mapeia mais)
 *   3. Remove `created_at`        (a entidade nao mapeia mais)
 *   4. Remove UNIQUE em (partner_id, city, state) que virou invalido
 *
 * Tudo protegido com verificacoes de existencia via INFORMATION_SCHEMA
 * para ser seguro de rodar mesmo que algum passo ja tenha ocorrido.
 */
final class Version20260626_monitored_cities_sync extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sincroniza monitored_cities: renomeia city->name, remove country e created_at';
    }

    public function up(Schema $schema): void
    {
        // 1. Renomeia city -> name (só se 'city' ainda existir)
        $this->addSql(<<<'SQL'
            SET @col_exists = (
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'monitored_cities'
                  AND COLUMN_NAME  = 'city'
            );
        SQL);
        $this->addSql(<<<'SQL'
            SET @sql = IF(
                @col_exists > 0,
                'ALTER TABLE monitored_cities CHANGE COLUMN city name VARCHAR(80) NOT NULL',
                'SELECT 1'
            );
        SQL);
        $this->addSql('PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt');

        // 2. Remove UNIQUE index UQ_city_partner (pode ter nome diferente, busca pelo padrao)
        $this->addSql(<<<'SQL'
            SET @idx_exists = (
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'monitored_cities'
                  AND INDEX_NAME   = 'UQ_city_partner'
            );
        SQL);
        $this->addSql(<<<'SQL'
            SET @sql2 = IF(
                @idx_exists > 0,
                'ALTER TABLE monitored_cities DROP INDEX UQ_city_partner',
                'SELECT 1'
            );
        SQL);
        $this->addSql('PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2');

        // 3. Remove coluna country (se existir)
        $this->addSql(<<<'SQL'
            SET @drop_country = (
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'monitored_cities'
                  AND COLUMN_NAME  = 'country'
            );
        SQL);
        $this->addSql(<<<'SQL'
            SET @sql3 = IF(
                @drop_country > 0,
                'ALTER TABLE monitored_cities DROP COLUMN country',
                'SELECT 1'
            );
        SQL);
        $this->addSql('PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3');

        // 4. Remove coluna created_at (se existir)
        $this->addSql(<<<'SQL'
            SET @drop_created = (
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'monitored_cities'
                  AND COLUMN_NAME  = 'created_at'
            );
        SQL);
        $this->addSql(<<<'SQL'
            SET @sql4 = IF(
                @drop_created > 0,
                'ALTER TABLE monitored_cities DROP COLUMN created_at',
                'SELECT 1'
            );
        SQL);
        $this->addSql('PREPARE stmt4 FROM @sql4; EXECUTE stmt4; DEALLOCATE PREPARE stmt4');
    }

    public function down(Schema $schema): void
    {
        // Reverte: name -> city, recria country e created_at
        $this->addSql('ALTER TABLE monitored_cities CHANGE COLUMN name city VARCHAR(80) NOT NULL');
        $this->addSql("ALTER TABLE monitored_cities ADD COLUMN country VARCHAR(10) DEFAULT 'BR'");
        $this->addSql("ALTER TABLE monitored_cities ADD COLUMN created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE UNIQUE INDEX UQ_city_partner ON monitored_cities (partner_id, city, state)');
    }
}
