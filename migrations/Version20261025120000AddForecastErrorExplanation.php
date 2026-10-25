<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000AddForecastErrorExplanation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona colunas error_explanation e error_cause_type em waze_tvt_forecast_audit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE waze_tvt_forecast_audit ADD error_explanation TEXT DEFAULT NULL, ADD error_cause_type VARCHAR(50) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE waze_tvt_forecast_audit DROP error_explanation, DROP error_cause_type");
    }
}
