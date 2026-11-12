<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000CreatePasswordResetToken extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create password_reset_token matching the PasswordResetToken entity';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($schema->hasTable('password_reset_token'), 'password_reset_token already exists; inspect its schema and migration history before proceeding.');
        $this->abortIf(!$schema->hasTable('user'), 'The user table must exist before creating password_reset_token.');

        $table = $schema->createTable('password_reset_token');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('user_id', 'integer');
        $table->addColumn('selector', 'string', ['length' => 32]);
        $table->addColumn('hashed_token', 'string', ['length' => 255]);
        $table->addColumn('expires_at', 'datetime_immutable');
        $table->addColumn('requested_at', 'datetime_immutable');
        $table->addColumn('used_at', 'datetime_immutable', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['selector'], 'uniq_password_reset_token_selector');
        $table->addIndex(['selector'], 'idx_password_reset_token_selector');
        $table->addIndex(['user_id'], 'idx_password_reset_token_user_id');
        $table->addIndex(['expires_at'], 'idx_password_reset_token_expires_at');
        $table->addForeignKeyConstraint('user', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_password_reset_token_user_id');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('password_reset_token');
    }
}
