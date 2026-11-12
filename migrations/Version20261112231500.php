<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112231500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preenche colunas de busca das irregularidades TVT e cria indices de filtro.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_tvt_irregularity
            ADD INDEX idx_tvt_irregularity_partner_reported (partner_id, reported_time),
            ADD INDEX idx_tvt_irregularity_partner_type (partner_id, type),
            ADD INDEX idx_tvt_irregularity_partner_severity (partner_id, severity),
            ADD INDEX idx_tvt_irregularity_partner_city (partner_id, city)');

        $this->addSql("UPDATE waze_tvt_irregularity
            SET street = COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.street')),
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.leadAlert.street'))
            ),
            city = COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.city')),
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.leadAlert.city'))
            ),
            reported_time = CASE
                WHEN JSON_TYPE(JSON_EXTRACT(payload, '$.updateTime')) IN ('INTEGER', 'DOUBLE')
                    AND CAST(JSON_EXTRACT(payload, '$.updateTime') AS DOUBLE) > 0
                THEN FROM_UNIXTIME(CAST(JSON_EXTRACT(payload, '$.updateTime') AS DOUBLE) / 1000)
                ELSE NULL
            END,
            severity = CASE CAST(JSON_EXTRACT(payload, '$.jamLevel') AS CHAR)
                WHEN '0' THEN 'Normal'
                WHEN '1' THEN 'Leve'
                WHEN '2' THEN 'Moderado'
                WHEN '3' THEN 'Alto'
                WHEN '4' THEN 'Muito alto'
                WHEN '5' THEN 'Parado'
                ELSE NULL
            END,
            latitude = CASE
                WHEN JSON_TYPE(JSON_EXTRACT(payload, '$.line[0].y')) IN ('INTEGER', 'DOUBLE')
                THEN CAST(JSON_EXTRACT(payload, '$.line[0].y') AS DOUBLE)
                ELSE NULL
            END,
            longitude = CASE
                WHEN JSON_TYPE(JSON_EXTRACT(payload, '$.line[0].x')) IN ('INTEGER', 'DOUBLE')
                THEN CAST(JSON_EXTRACT(payload, '$.line[0].x') AS DOUBLE)
                ELSE NULL
            END
            WHERE content_hash LIKE 'rootv1:%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waze_tvt_irregularity
            DROP INDEX idx_tvt_irregularity_partner_reported,
            DROP INDEX idx_tvt_irregularity_partner_type,
            DROP INDEX idx_tvt_irregularity_partner_severity,
            DROP INDEX idx_tvt_irregularity_partner_city');
    }
}
