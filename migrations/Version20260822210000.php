<?php

declare(strict_types=1);

namespace App\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration para otimizar indices das tabelas waze_tvt_routes e waze_tvt_snapshots
 * 
 * Problema: tabela waze_tvt_routes com ~381k registros e 1.79GB
 * Solucao: remover indice redundante e criar indice composto para filtro por snapshot + jam_level
 */
final class Version20260822210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Otimizar indices waze_tvt_routes - remover idx_tvt_routes_partner redundante e criar indice composto';
    }

    public function up(Schema $schema): void
    {
        // Remover indice redundante (sobre id, que ja tem PRIMARY KEY)
        $this->addSql('DROP INDEX IF EXISTS idx_tvt_routes_partner ON waze_tvt_routes');
        
        // Criar indice composto para filtro por snapshot_id + jam_level
        // Isso otimiza o JOIN com ordenacao por jam_level
        $this->addSql('CREATE INDEX idx_tvt_routes_snapshot_jam ON waze_tvt_routes (snapshot_id, jam_level)');
        
        // Opcional: adicionar indice em collected_at para filtro temporal
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tvt_snapshots_collected_at ON waze_tvt_snapshots (collected_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_tvt_routes_snapshot_jam ON waze_tvt_routes');
        $this->addSql('DROP INDEX IF EXISTS idx_tvt_snapshots_collected_at ON waze_tvt_snapshots');
        $this->addSql('CREATE INDEX idx_tvt_routes_partner ON waze_tvt_routes (id)');
    }
}
