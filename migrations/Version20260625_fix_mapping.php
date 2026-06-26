<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garante que colunas adicionadas pela Version20260625_waze_tvt e pela
 * Version20260625_partnerhub_new_fields existam de facto no banco.
 * Usa IF NOT EXISTS / IF EXISTS para ser idempotente.
 *
 * Também corrige a tabela waze_routes caso waze_id ainda nao exista.
 */
final class Version20260625_fix_mapping extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garante colunas de waze_routes, waze_alerts e waze_traffic_jams (idempotente)';
    }

    public function up(Schema $schema): void
    {
        // ── waze_routes: garante coluna waze_id ───────────────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE waze_routes
                ADD COLUMN IF NOT EXISTS waze_id VARCHAR(50) DEFAULT NULL
            SQL);

        // ── waze_alerts: garante colunas novas ───────────────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE waze_alerts
                ADD COLUMN IF NOT EXISTS n_comments          INT          DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS jam_uuid            VARCHAR(80)  DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS inscale             TINYINT(1)   DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS is_jam_unified_alert TINYINT(1)  DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS report_by_municipality_user TINYINT(1) DEFAULT NULL
            SQL);

        // ── waze_traffic_jams: garante colunas novas ─────────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE waze_traffic_jams
                ADD COLUMN IF NOT EXISTS waze_numeric_id INT          DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS speed           DECIMAL(8,3) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS blocking        TINYINT(1)   DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS severity        INT          DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reversão opcional — remove apenas o que foi adicionado aqui
        $this->addSql('ALTER TABLE waze_routes DROP COLUMN IF EXISTS waze_id');

        $this->addSql(<<<'SQL'
            ALTER TABLE waze_alerts
                DROP COLUMN IF EXISTS n_comments,
                DROP COLUMN IF EXISTS jam_uuid,
                DROP COLUMN IF EXISTS inscale,
                DROP COLUMN IF EXISTS is_jam_unified_alert,
                DROP COLUMN IF EXISTS report_by_municipality_user
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE waze_traffic_jams
                DROP COLUMN IF EXISTS waze_numeric_id,
                DROP COLUMN IF EXISTS speed,
                DROP COLUMN IF EXISTS blocking,
                DROP COLUMN IF EXISTS severity
            SQL);
    }
}
