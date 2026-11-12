<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona snapshot historico por coleta nas irregularidades TVT.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_tvt_irregularity_sample
            ADD COLUMN snapshot JSON NULL AFTER jam_level');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_tvt_irregularity_sample
            DROP COLUMN snapshot');
    }
}
