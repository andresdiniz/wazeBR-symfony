<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona as colunas de contagem por nível de congestionamento (jamLevel)
 * na tabela waze_counts, conforme a entidade WazeCount.
 *
 * Colunas: wazers_level_0, wazers_level_1, wazers_level_2,
 *          wazers_level_3, wazers_level_4, wazers_total
 * Tipo: DECIMAL(10,1) NULL — compatível com respostas decimais da API Waze.
 */
final class Version20260721235900_add_wazers_levels extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona colunas wazers_level_0..4 e wazers_total em waze_counts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_counts
            ADD COLUMN wazers_level_0 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 0 (livre)",
            ADD COLUMN wazers_level_1 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 1 (leve)",
            ADD COLUMN wazers_level_2 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 2 (moderado)",
            ADD COLUMN wazers_level_3 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 3 (intenso)",
            ADD COLUMN wazers_level_4 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 4 (parado)",
            ADD COLUMN wazers_total   DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Total de wazers em todos os jams"
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_counts
            DROP COLUMN wazers_level_0,
            DROP COLUMN wazers_level_1,
            DROP COLUMN wazers_level_2,
            DROP COLUMN wazers_level_3,
            DROP COLUMN wazers_level_4,
            DROP COLUMN wazers_total
        ');
    }
}
