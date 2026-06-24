<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona FK partner_id em waze_alerts, waze_traffic_jams e cemaden_data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_alerts ADD COLUMN partner_id INT NULL, ADD CONSTRAINT FK_waze_alerts_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE waze_traffic_jams ADD COLUMN partner_id INT NULL, ADD CONSTRAINT FK_waze_traffic_jams_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cemaden_data ADD COLUMN partner_id INT NULL, ADD CONSTRAINT FK_cemaden_data_partner FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_alerts DROP FOREIGN KEY FK_waze_alerts_partner, DROP COLUMN partner_id');
        $this->addSql('ALTER TABLE waze_traffic_jams DROP FOREIGN KEY FK_waze_traffic_jams_partner, DROP COLUMN partner_id');
        $this->addSql('ALTER TABLE cemaden_data DROP FOREIGN KEY FK_cemaden_data_partner, DROP COLUMN partner_id');
    }
}
