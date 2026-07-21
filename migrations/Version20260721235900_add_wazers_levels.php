<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona as colunas de contagem por nível de congestionamento (jamLevel)
 * na tabela waze_counts. Usa IF NOT EXISTS para ser idempotente — colunas
 * já existentes (ex: wazers_total) são ignoradas sem erro.
 */
final class Version20260721235900_add_wazers_levels extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona colunas wazers_level_0..4 e wazers_total em waze_counts (IF NOT EXISTS)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_counts
            ADD COLUMN IF NOT EXISTS wazers_level_0 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 0 (livre)",
            ADD COLUMN IF NOT EXISTS wazers_level_1 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 1 (leve)",
            ADD COLUMN IF NOT EXISTS wazers_level_2 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 2 (moderado)",
            ADD COLUMN IF NOT EXISTS wazers_level_3 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 3 (intenso)",
            ADD COLUMN IF NOT EXISTS wazers_level_4 DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Wazers em vias jamLevel 4 (parado)",
            ADD COLUMN IF NOT EXISTS wazers_total   DECIMAL(10,1) NULL DEFAULT NULL COMMENT "Total de wazers em todos os jams"
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_counts
            DROP COLUMN IF EXISTS wazers_level_0,
            DROP COLUMN IF EXISTS wazers_level_1,
            DROP COLUMN IF EXISTS wazers_level_2,
            DROP COLUMN IF EXISTS wazers_level_3,
            DROP COLUMN IF EXISTS wazers_level_4,
            DROP COLUMN IF EXISTS wazers_total
        ');
    }
}
