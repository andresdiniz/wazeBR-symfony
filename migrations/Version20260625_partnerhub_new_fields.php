<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona campos do PartnerHub API format=1 ausentes nas entidades anteriores.
 *
 * WazeAlert:
 *   + jam_uuid                    VARCHAR(80)  — UUID do jam associado
 *   + n_comments                  INT          — número de comentários
 *   + inscale                     TINYINT(1)   — visível na escala atual do mapa
 *   + is_jam_unified_alert        TINYINT(1)   — alerta unificado de jam
 *   + report_by_municipality_user TINYINT(1)   — relato por usuário municipal
 *
 * WazeTrafficJam:
 *   + waze_numeric_id             INT          — campo "id" numérico interno Waze
 *   + speed                       DECIMAL(8,3) — velocidade em m/s
 *   + blocking                    TINYINT(1)   — jam bloqueia a via
 *   + severity                    INT          — severidade do jam
 *   ~ length                      INT (era DECIMAL(10,2))
 */
final class Version20260625_partnerhub_new_fields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campos novos do PartnerHub API format=1: WazeAlert (jamUuid, nComments, inscale, isJamUnifiedAlert, reportByMunicipalityUser) + WazeTrafficJam (wazeNumericId, speed, blocking, severity, length->int)';
    }

    public function up(Schema $schema): void
    {
        // ── WazeAlert: novos campos do feed format=1 ────────────────────────
        $this->addSql('ALTER TABLE waze_alerts
            ADD COLUMN IF NOT EXISTS jam_uuid                    VARCHAR(80) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS n_comments                  INT         DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS inscale                     TINYINT(1)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS is_jam_unified_alert        TINYINT(1)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS report_by_municipality_user TINYINT(1)  DEFAULT NULL
        ');

        // ── WazeTrafficJam: novos campos + correção de tipo do length ─────────
        $this->addSql('ALTER TABLE waze_traffic_jams
            ADD COLUMN IF NOT EXISTS waze_numeric_id INT          DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS speed           DECIMAL(8,3) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS blocking        TINYINT(1)   DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS severity        INT          DEFAULT NULL
        ');

        // Converter length de DECIMAL(10,2) para INT (API retorna inteiro em metros)
        // Usa IF para verificar tipo atual e só alterar se ainda for DECIMAL
        $isDecimal = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'waze_traffic_jams'
               AND COLUMN_NAME  = 'length'
               AND DATA_TYPE    = 'decimal'"
        );

        if ($isDecimal) {
            $this->addSql(
                'ALTER TABLE waze_traffic_jams MODIFY COLUMN length INT DEFAULT NULL'
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_alerts
            DROP COLUMN IF EXISTS jam_uuid,
            DROP COLUMN IF EXISTS n_comments,
            DROP COLUMN IF EXISTS inscale,
            DROP COLUMN IF EXISTS is_jam_unified_alert,
            DROP COLUMN IF EXISTS report_by_municipality_user
        ');

        $this->addSql('ALTER TABLE waze_traffic_jams
            DROP COLUMN IF EXISTS waze_numeric_id,
            DROP COLUMN IF EXISTS speed,
            DROP COLUMN IF EXISTS blocking,
            DROP COLUMN IF EXISTS severity
        ');

        // Reverte length para DECIMAL
        $this->addSql(
            'ALTER TABLE waze_traffic_jams MODIFY COLUMN length DECIMAL(10,2) DEFAULT NULL'
        );
    }
}
