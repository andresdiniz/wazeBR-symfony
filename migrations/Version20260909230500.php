<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add endpoint_url column to waze_feed table for storing full Waze API URL
 */
final class Version20260909230500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add endpoint_url column to waze_feed table';
    }

    public function up(): void
    {
        $table = $this->connection->createSchemaManager()->introspectTable('waze_feed');
        
        if (!$table->hasColumn('endpoint_url')) {
            $this->addSql('ALTER TABLE waze_feed ADD COLUMN endpoint_url VARCHAR(500) DEFAULT NULL COMMENT "Full Waze API endpoint URL" AFTER feed_id');
        }
    }

    public function down(): void
    {
        $table = $this->connection->createSchemaManager()->introspectTable('waze_feed');
        
        if ($table->hasColumn('endpoint_url')) {
            $this->addSql('ALTER TABLE waze_feed DROP COLUMN endpoint_url');
        }
    }
}
