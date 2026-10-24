<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Converte perfis administrativos vinculados a parceiro em administradores do parceiro.';
    }

    public function up(Schema $schema): void
    {
        $users = $this->connection->fetchAllAssociative('SELECT id, roles FROM user WHERE partner_id IS NOT NULL');
        foreach ($users as $user) {
            $roles = json_decode((string) $user['roles'], true);
            if (!is_array($roles)) {
                continue;
            }

            $roles = array_values(array_diff($roles, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']));
            if (!in_array('ROLE_PARTNER_ADMIN', $roles, true)) {
                $roles[] = 'ROLE_PARTNER_ADMIN';
            }
            $this->connection->update('user', ['roles' => json_encode($roles, JSON_THROW_ON_ERROR)], ['id' => $user['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Downgrading linked global administrators would grant global access again.');
    }
}
