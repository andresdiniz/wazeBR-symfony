<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix: coluna `coordinates` em `waze_routes` deve ser NULL-able.
 *
 * O erro SQLSTATE[23000] Column 'coordinates' cannot be null ocorria porque
 * a coluna foi criada como NOT NULL em migration anterior, mas a entidade
 * WazeRoute declara o campo como nullable=true e o comando TVT jamais
 * chama setCoordinates() em rotas criadas via feed (coordinates é opcional,
 * usado apenas no modo Routing API).
 */
final class Version20260627_fix_coordinates_nullable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Torna a coluna coordinates de waze_routes nullable (era NOT NULL por engano).';
    }

    public function up(Schema $schema): void
    {
        // Verifica se a coluna existe antes de alterar
        $this->addSql(
            'ALTER TABLE waze_routes
             MODIFY COLUMN coordinates JSON NULL COMMENT "Coordenadas from/to para modo Routing API (opcional, NULL em rotas TVT)"'
        );
    }

    public function down(Schema $schema): void
    {
        // Revert: torna NOT NULL novamente (apenas se não houver linhas com NULL)
        $this->addSql(
            'ALTER TABLE waze_routes MODIFY COLUMN coordinates JSON NOT NULL'
        );
    }
}
