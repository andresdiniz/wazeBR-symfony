<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration completa compatível com MariaDB.
 * Substitui todos os RENAME INDEX por DROP + ADD (MariaDB não suporta RENAME INDEX).
 */
final class Version20260624160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sincroniza schema completo com MariaDB (sem RENAME INDEX)';
    }

    public function up(Schema $schema): void
    {
        // activity_log
        $this->addSql('ALTER TABLE activity_log CHANGE description description VARCHAR(255) DEFAULT NULL, CHANGE context context JSON DEFAULT NULL, CHANGE ip_address ip_address VARCHAR(45) DEFAULT NULL');
        $this->addSql('ALTER TABLE activity_log DROP INDEX idx_activity_log_user, ADD INDEX IDX_FD06F647A76ED395 (user_id)');

        // cemaden_data
        $this->addSql('ALTER TABLE cemaden_data DROP FOREIGN KEY FK_cemaden_partner');
        $this->addSql('ALTER TABLE cemaden_data CHANGE accumulated_rain accumulated_rain NUMERIC(8, 2) DEFAULT NULL, CHANGE water_level water_level NUMERIC(8, 2) DEFAULT NULL, CHANGE alert_level alert_level VARCHAR(20) DEFAULT NULL, CHANGE measured_at measured_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE partner_id partner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cemaden_data ADD CONSTRAINT FK_E3BE8F869393F8FE FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cemaden_data DROP INDEX idx_cemaden_partner, ADD INDEX IDX_E3BE8F869393F8FE (partner_id)');

        // monitored_cities
        $this->addSql('ALTER TABLE monitored_cities CHANGE country country VARCHAR(10) DEFAULT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE monitored_cities DROP INDEX uq_city_partner, ADD UNIQUE INDEX UNIQ_EDAD66069393F8FE2D5B0234A393D2FB (partner_id, city, state)');

        // monitored_links
        $this->addSql('ALTER TABLE monitored_links CHANGE type type VARCHAR(40) NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE monitored_links DROP INDEX idx_links_partner, ADD INDEX IDX_4F8C30719393F8FE (partner_id)');

        // notifications
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_notifications_user');
        $this->addSql('ALTER TABLE notifications ADD reference_id VARCHAR(120) DEFAULT NULL, DROP payload, CHANGE title title VARCHAR(180) NOT NULL, CHANGE body body LONGTEXT NOT NULL, CHANGE is_read is_read TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notifications DROP INDEX idx_notif_partner, ADD INDEX IDX_6000B0D39393F8FE (partner_id)');
        $this->addSql('ALTER TABLE notifications DROP INDEX idx_notifications_user, ADD INDEX IDX_6000B0D3A76ED395 (user_id)');

        // partners
        $this->addSql('ALTER TABLE partners CHANGE bbox bbox VARCHAR(100) DEFAULT NULL, CHANGE cemaden_states cemaden_states JSON NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE partners DROP INDEX uniq_partners_slug, ADD UNIQUE INDEX UNIQ_EFEB5164989D9B62 (slug)');
        $this->addSql('ALTER TABLE partners DROP INDEX uniq_partners_email, ADD UNIQUE INDEX UNIQ_EFEB5164E7927C74 (email)');
        $this->addSql('ALTER TABLE partners DROP INDEX uniq_partners_token, ADD UNIQUE INDEX UNIQ_EFEB51647BA2F5EB (api_token)');

        // users
        $this->addSql('ALTER TABLE users CHANGE roles roles JSON NOT NULL, CHANGE last_login_at last_login_at DATETIME DEFAULT NULL');

        // waze_alerts
        $this->addSql('ALTER TABLE waze_alerts DROP FOREIGN KEY FK_waze_alerts_partner');
        $this->addSql('ALTER TABLE waze_alerts CHANGE subtype subtype VARCHAR(60) DEFAULT NULL, CHANGE street street VARCHAR(120) DEFAULT NULL, CHANGE city city VARCHAR(80) DEFAULT NULL, CHANGE country country VARCHAR(10) DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE partner_id partner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_alerts ADD CONSTRAINT FK_EC1886D09393F8FE FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE waze_alerts DROP INDEX idx_waze_alerts_partner, ADD INDEX IDX_EC1886D09393F8FE (partner_id)');

        // waze_routes
        $this->addSql('ALTER TABLE waze_routes CHANGE description description VARCHAR(255) DEFAULT NULL, CHANGE coordinates coordinates JSON NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE waze_routes DROP INDEX idx_routes_partner, ADD INDEX IDX_29B784089393F8FE (partner_id)');

        // waze_route_links
        $this->addSql('ALTER TABLE waze_route_links CHANGE coordinates coordinates JSON NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE waze_route_links DROP INDEX idx_route_links_route, ADD INDEX IDX_77F0798634ECB4E6 (route_id)');

        // waze_traffic_jams
        $this->addSql('ALTER TABLE waze_traffic_jams DROP FOREIGN KEY FK_waze_jams_partner');
        $this->addSql('ALTER TABLE waze_traffic_jams CHANGE street street VARCHAR(120) DEFAULT NULL, CHANGE city city VARCHAR(80) DEFAULT NULL, CHANGE speed_kmh speed_kmh NUMERIC(8, 2) DEFAULT NULL, CHANGE length length NUMERIC(8, 2) DEFAULT NULL, CHANGE line line JSON DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE partner_id partner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE waze_traffic_jams ADD CONSTRAINT FK_F5313B089393F8FE FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE waze_traffic_jams DROP INDEX idx_waze_jams_partner, ADD INDEX IDX_F5313B089393F8FE (partner_id)');
    }

    public function down(Schema $schema): void
    {
        // Reversão simplificada — restaura nomes originais dos índices
        $this->addSql('ALTER TABLE activity_log DROP INDEX IDX_FD06F647A76ED395, ADD INDEX idx_activity_log_user (user_id)');
        $this->addSql('ALTER TABLE cemaden_data DROP INDEX IDX_E3BE8F869393F8FE, ADD INDEX idx_cemaden_partner (partner_id)');
        $this->addSql('ALTER TABLE monitored_cities DROP INDEX UNIQ_EDAD66069393F8FE2D5B0234A393D2FB, ADD UNIQUE INDEX uq_city_partner (partner_id, city, state)');
        $this->addSql('ALTER TABLE monitored_links DROP INDEX IDX_4F8C30719393F8FE, ADD INDEX idx_links_partner (partner_id)');
        $this->addSql('ALTER TABLE notifications ADD payload JSON DEFAULT NULL, DROP reference_id');
        $this->addSql('ALTER TABLE notifications DROP INDEX IDX_6000B0D39393F8FE, ADD INDEX idx_notif_partner (partner_id)');
        $this->addSql('ALTER TABLE notifications DROP INDEX IDX_6000B0D3A76ED395, ADD INDEX idx_notifications_user (user_id)');
        $this->addSql('ALTER TABLE partners DROP INDEX UNIQ_EFEB5164989D9B62, ADD UNIQUE INDEX uniq_partners_slug (slug)');
        $this->addSql('ALTER TABLE partners DROP INDEX UNIQ_EFEB5164E7927C74, ADD UNIQUE INDEX uniq_partners_email (email)');
        $this->addSql('ALTER TABLE partners DROP INDEX UNIQ_EFEB51647BA2F5EB, ADD UNIQUE INDEX uniq_partners_token (api_token)');
        $this->addSql('ALTER TABLE waze_alerts DROP INDEX IDX_EC1886D09393F8FE, ADD INDEX idx_waze_alerts_partner (partner_id)');
        $this->addSql('ALTER TABLE waze_routes DROP INDEX IDX_29B784089393F8FE, ADD INDEX idx_routes_partner (partner_id)');
        $this->addSql('ALTER TABLE waze_route_links DROP INDEX IDX_77F0798634ECB4E6, ADD INDEX idx_route_links_route (route_id)');
        $this->addSql('ALTER TABLE waze_traffic_jams DROP INDEX IDX_F5313B089393F8FE, ADD INDEX idx_waze_jams_partner (partner_id)');
    }
}
