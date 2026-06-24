<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona FK partner_id em waze_alerts, waze_traffic_jams, cemaden_data e activity_log (idempotente)';
    }

    public function up(Schema $schema): void
    {
        // Usa stored procedure inline para adicionar colunas apenas se nao existirem (idempotente)
        $tables = [
            'waze_alerts'      => 'FK_waze_alerts_partner',
            'waze_traffic_jams'=> 'FK_waze_traffic_jams_partner',
            'cemaden_data'     => 'FK_cemaden_data_partner',
            'activity_log'     => 'FK_activity_log_partner',
        ];

        foreach ($tables as $table => $fkName) {
            $this->addSql(<<<SQL
                SET @dbname = DATABASE();
                SET @tbl = '{$table}';
                SET @col = 'partner_id';
                SET @query = IF(
                    (
                        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = @dbname
                          AND TABLE_NAME  = @tbl
                          AND COLUMN_NAME = @col
                    ) = 0,
                    CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `partner_id` INT NULL, ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`partner_id`) REFERENCES `partners`(`id`) ON DELETE SET NULL'),
                    'SELECT 1'
                );
                PREPARE stmt FROM @query;
                EXECUTE stmt;
                DEALLOCATE PREPARE stmt;
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_alerts DROP FOREIGN KEY FK_waze_alerts_partner, DROP COLUMN partner_id');
        $this->addSql('ALTER TABLE waze_traffic_jams DROP FOREIGN KEY FK_waze_traffic_jams_partner, DROP COLUMN partner_id');
        $this->addSql('ALTER TABLE cemaden_data DROP FOREIGN KEY FK_cemaden_data_partner, DROP COLUMN partner_id');
        $this->addSql('ALTER TABLE activity_log DROP FOREIGN KEY FK_activity_log_partner, DROP COLUMN partner_id');
    }
}
