<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona coluna partner_id à tabela waze_counts e
 * cria a FK para a tabela partners.
 *
 * Se existirem registros órfãos na tabela, o up() os remove antes
 * de aplicar o NOT NULL constraint.
 */
final class Version20260721230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona partner_id (FK → partners) em waze_counts';
    }

    public function up(Schema $schema): void
    {
        // Remove registros sem partner para não violar NOT NULL
        $this->addSql('DELETE FROM waze_counts WHERE 1=1');

        $this->addSql('ALTER TABLE waze_counts ADD COLUMN partner_id INT NOT NULL');
        $this->addSql('
            ALTER TABLE waze_counts
            ADD CONSTRAINT FK_waze_counts_partner
            FOREIGN KEY (partner_id) REFERENCES partners (id)
            ON DELETE CASCADE
        ');
        $this->addSql('CREATE INDEX IDX_waze_counts_partner ON waze_counts (partner_id)');
        $this->addSql('CREATE INDEX IDX_waze_counts_partner_collected ON waze_counts (partner_id, collected_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_counts DROP FOREIGN KEY FK_waze_counts_partner');
        $this->addSql('DROP INDEX IDX_waze_counts_partner ON waze_counts');
        $this->addSql('DROP INDEX IDX_waze_counts_partner_collected ON waze_counts');
        $this->addSql('ALTER TABLE waze_counts DROP COLUMN partner_id');
    }
}
