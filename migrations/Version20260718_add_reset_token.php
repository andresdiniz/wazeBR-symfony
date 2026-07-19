<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona reset_token e reset_token_expires_at na tabela users.
 * Substitui o armazenamento em memória (array estático) do fluxo antigo
 * de redefinição de senha, que não sobrevivia a múltiplos workers/processos.
 */
final class Version20260718_add_reset_token extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona reset_token (único) e reset_token_expires_at em users para redefinição de senha persistida.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE users ADD reset_token VARCHAR(64) DEFAULT NULL, ADD reset_token_expires_at DATETIME DEFAULT NULL'
        );
        $this->addSql(
            'CREATE UNIQUE INDEX UNIQ_USERS_RESET_TOKEN ON users (reset_token)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_USERS_RESET_TOKEN ON users');
        $this->addSql('ALTER TABLE users DROP reset_token, DROP reset_token_expires_at');
    }
}
