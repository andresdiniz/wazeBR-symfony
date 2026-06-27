<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reestrutura waze_route_snapshots:
 *   - Renomeia avg_time → time
 *   - Adiciona historic_time, length, jam_level, historic_speed
 *
 * A coluna avg_speed já existe e é mantida.
 */
final class Version20260627_fix_route_snapshots_columns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reestrutura waze_route_snapshots para refletir campos brutos da API Waze';
    }

    public function up(Schema $schema): void
    {
        // 1. Renomear avg_time → time (guarda dado existente)
        $this->addSql('ALTER TABLE waze_route_snapshots CHANGE avg_time `time` INT DEFAULT NULL');

        // 2. Adicionar colunas novas
        $this->addSql('ALTER TABLE waze_route_snapshots
            ADD COLUMN historic_time INT DEFAULT NULL AFTER `time`,
            ADD COLUMN length INT DEFAULT NULL AFTER historic_time,
            ADD COLUMN jam_level INT DEFAULT NULL AFTER length,
            ADD COLUMN historic_speed DOUBLE PRECISION DEFAULT NULL AFTER avg_speed
        ');
    }

    public function down(Schema $schema): void
    {
        // Reverter: remover colunas adicionadas e restaurar nome avg_time
        $this->addSql('ALTER TABLE waze_route_snapshots
            DROP COLUMN historic_time,
            DROP COLUMN length,
            DROP COLUMN jam_level,
            DROP COLUMN historic_speed
        ');
        $this->addSql('ALTER TABLE waze_route_snapshots CHANGE `time` avg_time INT DEFAULT NULL');
    }
}
