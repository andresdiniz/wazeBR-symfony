<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona coluna field_agent_permissions (JSON) à tabela users.
 * Necessária para configurar permissões individuais de agentes de via.
 */
final class Version20260716_roles extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add field_agent_permissions JSON column to users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE users ADD field_agent_permissions JSON DEFAULT NULL COMMENT "Permissões customizadas para ROLE_FIELD_AGENT"'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN field_agent_permissions');
    }
}
