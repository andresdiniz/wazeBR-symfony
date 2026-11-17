<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117120000CreateUserNotificationPreferences extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_notification_preference and user_notification_subscription (per-user e-mail preferences by alert/event type and subtype)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'Esta migration é específica para MySQL/MariaDB.',
        );

        // SQL direto + IF NOT EXISTS: seguro para reexecutar e independente do que o Doctrine enxerga do schema.
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_notification_preference (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                email_enabled TINYINT(1) DEFAULT 1 NOT NULL,
                alerts_enabled TINYINT(1) DEFAULT 1 NOT NULL,
                events_enabled TINYINT(1) DEFAULT 0 NOT NULL,
                briefing_enabled TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_user_notification_preference_user (user_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_user_notification_preference_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS user_notification_subscription (
                id INT AUTO_INCREMENT NOT NULL,
                preference_id INT NOT NULL,
                category VARCHAR(16) NOT NULL,
                type VARCHAR(50) NOT NULL,
                subtype VARCHAR(100) DEFAULT '*' NOT NULL,
                UNIQUE INDEX uniq_user_notification_subscription (preference_id, category, type, subtype),
                INDEX idx_user_notification_subscription_cat_type (category, type),
                PRIMARY KEY (id),
                CONSTRAINT fk_user_notification_subscription_preference FOREIGN KEY (preference_id) REFERENCES user_notification_preference (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_notification_subscription');
        $this->addSql('DROP TABLE IF EXISTS user_notification_preference');
    }
}
