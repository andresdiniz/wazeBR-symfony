<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona refresh_interval_minutes na tabela partners.
 * NULL = usa o intervalo padrão do sistema.
 */
final class Version20260716_add_refresh_interval extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona refresh_interval_minutes em partners para controle de intervalo de coleta por parceiro.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE partners ADD refresh_interval_minutes INT DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE partners DROP COLUMN refresh_interval_minutes'
        );
    }
}
