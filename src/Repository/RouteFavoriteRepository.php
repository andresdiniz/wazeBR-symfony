<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;

/**
 * Favoritos de rota por usuário.
 */
final class RouteFavoriteRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** A rota existe, está ativa e é visível no escopo do usuário? */
    public function routeVisible(int $routeId, ?Partner $partner): bool
    {
        $params = ['id' => $routeId];
        $sql = 'SELECT 1 FROM waze_tvt_route WHERE id = :id AND is_active = 1';

        if ($partner !== null) {
            $sql .= ' AND partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        return $this->connection->fetchOne($sql . ' LIMIT 1', $params) !== false;
    }

    public function isFavorite(int $userId, int $routeId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM user_favorite_route WHERE user_id = :u AND route_id = :r LIMIT 1',
            ['u' => $userId, 'r' => $routeId]
        ) !== false;
    }

    /** Alterna o favorito e devolve o NOVO estado (true = favoritada). */
    public function toggle(int $userId, int $routeId): bool
    {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM user_favorite_route WHERE user_id = :u AND route_id = :r',
            ['u' => $userId, 'r' => $routeId]
        );

        if ($deleted > 0) {
            return false;
        }

        // INSERT IGNORE: dois cliques simultâneos não geram erro de chave única.
        $this->connection->executeStatement(
            'INSERT IGNORE INTO user_favorite_route (user_id, route_id, created_at)
             VALUES (:u, :r, UTC_TIMESTAMP())',
            ['u' => $userId, 'r' => $routeId]
        );

        return true;
    }
}
