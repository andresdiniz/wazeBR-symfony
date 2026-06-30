<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrige dados corrompidos na tabela cemaden_stations:
 *  - Campo `municipio` da estação 311830410H estava com JSON bruto da API
 *  - Adiciona colunas `lat` e `lng` para coordenadas fixas das estações
 */
final class Version20260630_fix_station_municipio extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corrige municipio corrompido (id=1) e adiciona lat/lng em cemaden_stations';
    }

    public function up(Schema $schema): void
    {
        // 1. Corrige o municipio da estação pluviométrica Rio Bananeiras
        $this->addSql(
            "UPDATE cemaden_stations
             SET municipio = 'Conselheiro Lafaiete'
             WHERE cod_estacao = '311830410H'
               AND municipio LIKE '{%'"
        );

        // 2. Adiciona colunas de coordenadas fixas (nullable — preenchidas na 1ª coleta ou pelo admin)
        $this->addSql(
            'ALTER TABLE cemaden_stations
             ADD COLUMN IF NOT EXISTS lat  DECIMAL(10,6) NULL COMMENT "Latitude da estação",
             ADD COLUMN IF NOT EXISTS lng  DECIMAL(10,6) NULL COMMENT "Longitude da estação"'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cemaden_stations DROP COLUMN IF EXISTS lat');
        $this->addSql('ALTER TABLE cemaden_stations DROP COLUMN IF EXISTS lng');
        // Não reverte dado corrompido — operação destrutiva
    }
}
