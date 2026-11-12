<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112223000 extends AbstractMigration
{
    public function getDescription(): string { return 'Armazena medições de irregularidades TVT por coleta.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE waze_tvt_irregularity_sample (id BIGINT AUTO_INCREMENT NOT NULL, irregularity_id INT NOT NULL, partner_id INT NOT NULL, observed_at DATETIME NOT NULL, observed_seconds DOUBLE PRECISION DEFAULT NULL, historic_seconds DOUBLE PRECISION DEFAULT NULL, delay_seconds DOUBLE PRECISION DEFAULT NULL, jam_level SMALLINT DEFAULT NULL, INDEX idx_irreg_sample_partner_time (partner_id, observed_at), UNIQUE INDEX uniq_irreg_sample_observation (irregularity_id, observed_at), PRIMARY KEY(id), CONSTRAINT fk_irreg_sample_parent FOREIGN KEY (irregularity_id) REFERENCES waze_tvt_irregularity (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE waze_tvt_irregularity_sample'); }
}
