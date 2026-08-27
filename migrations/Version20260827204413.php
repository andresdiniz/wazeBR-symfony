<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona à tabela `users`:
 *   - last_login_at (data/hora do último login bem-sucedido)
 *   - last_login_ip (IP do último login bem-sucedido)
 *   - field_agent_permissions (permissões de agente de campo, JSON)
 *
 * Nenhuma migration existia neste projeto antes desta (pasta migrations/
 * estava vazia) — se o schema atual não tiver sido criado via
 * doctrine:migrations, rode primeiro `doctrine:migrations:sync-metadata-storage`
 * antes de `doctrine:migrations:migrate`. Se preferir aplicar direto sem
 * o sistema de migrations, use o SQL puro documentado no final deste
 * arquivo (comentário).
 */
final class Version20260827204413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona last_login_at, last_login_ip e field_agent_permissions em users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE users '
            . 'ADD last_login_at DATETIME DEFAULT NULL, '
            . 'ADD last_login_ip VARCHAR(45) DEFAULT NULL, '
            . 'ADD field_agent_permissions JSON DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE users '
            . 'DROP last_login_at, '
            . 'DROP last_login_ip, '
            . 'DROP field_agent_permissions'
        );
    }
}

/*
 * SQL PURO (alternativa se não usar doctrine:migrations na Hostinger):
 * rode direto no phpMyAdmin, na tabela `users` do banco de produção.
 *
 * ALTER TABLE users
 *   ADD last_login_at DATETIME DEFAULT NULL,
 *   ADD last_login_ip VARCHAR(45) DEFAULT NULL,
 *   ADD field_agent_permissions JSON DEFAULT NULL;
 */
