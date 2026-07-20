<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona os campos schedule e lane_impact (formato parcial) em cifs_event,
 * completando a cobertura da spec CIFS (tags opcionais que faltavam).
 */
final class Version20260720_add_cifs_schedule_lane_impact extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona schedule (json) e lane_impact_closed_lanes/lane_impact_roadside em cifs_event.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE cifs_event ADD schedule JSON DEFAULT NULL, ADD lane_impact_closed_lanes INT DEFAULT NULL, ADD lane_impact_roadside VARCHAR(10) DEFAULT NULL'
        );

        // POLICE_WITH_MOBILE_CAMERA é um subtipo válido na spec CIFS que não
        // estava mapeado; insere a tradução sem depender de rodar fixtures de novo.
        $this->addSql("INSERT INTO cifs_event_type (type, subtype, locale, label, description) VALUES
            ('POLICE', 'POLICE_WITH_MOBILE_CAMERA', 'pt', 'Radar móvel', NULL),
            ('POLICE', 'POLICE_WITH_MOBILE_CAMERA', 'en', 'Police with mobile camera', NULL),
            ('POLICE', 'POLICE_WITH_MOBILE_CAMERA', 'es', 'Policía con radar móvil', NULL)
            ON DUPLICATE KEY UPDATE label = VALUES(label)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM cifs_event_type WHERE type = 'POLICE' AND subtype = 'POLICE_WITH_MOBILE_CAMERA'");
        $this->addSql(
            'ALTER TABLE cifs_event DROP schedule, DROP lane_impact_closed_lanes, DROP lane_impact_roadside'
        );
    }
}
