<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Platforms\MySQLPlatform;

/**
 * Add feed_id column to waze_feed table for storing Waze's numeric feed ID
 */
final class Version20260909230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed_id column to waze_feed table';
    }

    public function up(): void
    {
        $table = $this->connection->createSchemaManager()->introspectTable('waze_feed');
        
        if (!$table->hasColumn('feed_id')) {
            $this->addSql('ALTER TABLE waze_feed ADD COLUMN feed_id INT DEFAULT NULL COMMENT "Waze numeric feed ID" AFTER feed_uuid');
            $this->addSql('ALTER TABLE waze_feed ADD INDEX IDX_WAZE_FEED_ID (feed_id)');
        }
    }

    public function down(): void
    {
        $table = $this->connection->createSchemaManager()->introspectTable('waze_feed');
        
        if ($table->hasColumn('feed_id')) {
            $this->addSql('ALTER TABLE waze_feed DROP INDEX IDX_WAZE_FEED_ID');
            $this->addSql('ALTER TABLE waze_feed DROP COLUMN feed_id');
        }
    }
}
