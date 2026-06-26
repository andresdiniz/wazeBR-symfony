<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sincroniza monitored_links com a entidade MonitoredLink atual.
 *
 * Tabela original (Version20260002000002):
 *   id, partner_id, name VARCHAR(120), url VARCHAR(255),
 *   type VARCHAR(40) DEFAULT 'generic', is_active, created_at
 *
 * Entidade atual espera:
 *   id, partner_id, url VARCHAR(500), label VARCHAR(120) NULL,
 *   feed_format INT DEFAULT 1, is_active, created_at, last_collected_at
 *
 * Operacoes:
 *   1. CHANGE name -> label  (preserva dados existentes)
 *   2. ADD feed_format       IF NOT EXISTS
 *   3. ADD last_collected_at IF NOT EXISTS
 *   4. DROP type             IF EXISTS
 *   5. MODIFY url            para VARCHAR(500) IF necessario
 */
final class Version20260626_monitored_links_sync extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sincroniza monitored_links: name->label, add feed_format e last_collected_at, drop type';
    }

    public function up(Schema $schema): void
    {
        // 1. Renomeia name -> label (so se 'name' ainda existir)
        $this->addSql(<<<'SQL'
            SET @has_name = (
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'monitored_links'
                  AND COLUMN_NAME  = 'name'
            )
        SQL);
        $this->addSql(<<<'SQL'
            SET @sql1 = IF(
                @has_name > 0,
                'ALTER TABLE monitored_links CHANGE COLUMN `name` `label` VARCHAR(120) DEFAULT NULL',
                'SELECT 1'
            )
        SQL);
        $this->addSql('PREPARE s1 FROM @sql1; EXECUTE s1; DEALLOCATE PREPARE s1');

        // 2. Adiciona feed_format IF NOT EXISTS
        $this->addSql(<<<'SQL'
            ALTER TABLE monitored_links
                ADD COLUMN IF NOT EXISTS feed_format INT NOT NULL DEFAULT 1
        SQL);

        // 3. Adiciona last_collected_at IF NOT EXISTS
        $this->addSql(<<<'SQL'
            ALTER TABLE monitored_links
                ADD COLUMN IF NOT EXISTS last_collected_at DATETIME DEFAULT NULL
                    COMMENT '(DC2Type:datetime_immutable)'
        SQL);

        // 4. Alarga url para VARCHAR(500) se ainda for VARCHAR(255)
        $this->addSql(<<<'SQL'
            SET @url_len = (
                SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'monitored_links'
                  AND COLUMN_NAME  = 'url'
            )
        SQL);
        $this->addSql(<<<'SQL'
            SET @sql2 = IF(
                @url_len IS NOT NULL AND @url_len < 500,
                'ALTER TABLE monitored_links MODIFY COLUMN url VARCHAR(500) NOT NULL',
                'SELECT 1'
            )
        SQL);
        $this->addSql('PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2');

        // 5. Remove coluna type IF EXISTS
        $this->addSql(<<<'SQL'
            SET @has_type = (
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'monitored_links'
                  AND COLUMN_NAME  = 'type'
            )
        SQL);
        $this->addSql(<<<'SQL'
            SET @sql3 = IF(
                @has_type > 0,
                'ALTER TABLE monitored_links DROP COLUMN type',
                'SELECT 1'
            )
        SQL);
        $this->addSql('PREPARE s3 FROM @sql3; EXECUTE s3; DEALLOCATE PREPARE s3');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitored_links CHANGE COLUMN label name VARCHAR(120) NOT NULL');
        $this->addSql("ALTER TABLE monitored_links ADD COLUMN type VARCHAR(40) NOT NULL DEFAULT 'generic'");
        $this->addSql('ALTER TABLE monitored_links DROP COLUMN IF EXISTS feed_format');
        $this->addSql('ALTER TABLE monitored_links DROP COLUMN IF EXISTS last_collected_at');
        $this->addSql('ALTER TABLE monitored_links MODIFY COLUMN url VARCHAR(255) NOT NULL');
    }
}
