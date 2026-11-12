<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112210000 extends AbstractMigration
{
    public function getDescription(): string { return 'Permite irregularidades TVT independentes de rotas monitoradas.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_tvt_irregularity MODIFY route_id INT DEFAULT NULL, MODIFY waze_route_id VARCHAR(100) DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM waze_tvt_irregularity WHERE route_id IS NULL OR waze_route_id IS NULL') > 0, 'Há irregularidades sem rota. O rollback não pode apagar ou atribuir rotas automaticamente.');
        $this->addSql('ALTER TABLE waze_tvt_irregularity MODIFY route_id INT NOT NULL, MODIFY waze_route_id VARCHAR(100) NOT NULL');
    }
}
