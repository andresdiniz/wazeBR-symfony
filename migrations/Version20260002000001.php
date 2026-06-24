<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260002000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona partner_id nas tabelas: users, waze_alerts, waze_traffic_jams, cemaden_data, notifications, activity_log';
    }

    public function up(Schema $schema): void
    {
        // users (nullable: super admin nao tem parceiro)
        $this->addSql('ALTER TABLE users ADD partner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_users_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_users_partner ON users (partner_id)');

        // waze_alerts
        $this->addSql('ALTER TABLE waze_alerts ADD partner_id INT NOT NULL');
        $this->addSql('ALTER TABLE waze_alerts ADD CONSTRAINT FK_waze_alerts_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_waze_alerts_partner ON waze_alerts (partner_id)');

        // waze_traffic_jams
        $this->addSql('ALTER TABLE waze_traffic_jams ADD partner_id INT NOT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jams ADD CONSTRAINT FK_waze_jams_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_waze_jams_partner ON waze_traffic_jams (partner_id)');

        // cemaden_data
        $this->addSql('ALTER TABLE cemaden_data ADD partner_id INT NOT NULL');
        $this->addSql('ALTER TABLE cemaden_data ADD CONSTRAINT FK_cemaden_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_cemaden_partner ON cemaden_data (partner_id)');

        // notifications
        $this->addSql('ALTER TABLE notifications ADD partner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_notif_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_notif_partner ON notifications (partner_id)');

        // activity_log
        $this->addSql('ALTER TABLE activity_log ADD partner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_log_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_log_partner ON activity_log (partner_id)');
    }

    public function down(Schema $schema): void
    {
        $tables = [
            'users'             => 'FK_users_partner',
            'waze_alerts'       => 'FK_waze_alerts_partner',
            'waze_traffic_jams' => 'FK_waze_jams_partner',
            'cemaden_data'      => 'FK_cemaden_partner',
            'notifications'     => 'FK_notif_partner',
            'activity_log'      => 'FK_log_partner',
        ];

        foreach ($tables as $table => $fk) {
            $this->addSql("ALTER TABLE {$table} DROP FOREIGN KEY {$fk}");
            $this->addSql("ALTER TABLE {$table} DROP COLUMN partner_id");
        }
    }
}
