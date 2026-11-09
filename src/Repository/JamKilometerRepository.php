<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\DBAL\Connection;

final class JamKilometerRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{currentKm: float, last7DaysKm: float} */
    public function getSummary(?Partner $partner, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($partner, $filters);

        $row = $this->connection->executeQuery(
            "SELECT
                COALESCE(SUM(CASE WHEN j.is_active = 1 THEN j.length END), 0) AS current_length,
                COALESCE(SUM(CASE WHEN j.collected_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY THEN j.length END), 0) AS week_length
             FROM waze_jams j
             {$where}",
            $params
        )->fetchAssociative() ?: [];

        return [
            'currentKm' => round(((int) ($row['current_length'] ?? 0)) / 1000, 1),
            'last7DaysKm' => round(((int) ($row['week_length'] ?? 0)) / 1000, 1),
        ];
    }

    /** @return array{0: string, 1: array<string,mixed>} */
    private function buildWhere(?Partner $partner, array $filters): array
    {
        $parts = ['1=1'];
        $params = [];

        if ($partner !== null) {
            $parts[] = 'j.partner_id = :pid';
            $params['pid'] = $partner->getId();
        }

        $levelMin = max(0, min(5, (int) ($filters['level_min'] ?? 0)));

        if ($levelMin > 0) {
            $parts[] = 'j.level >= :lvl';
            $params['lvl'] = $levelMin;
        }

        $city = mb_substr((string) ($filters['city'] ?? ''), 0, 100);

        if ($city !== '') {
            $parts[] = 'j.city = :city';
            $params['city'] = $city;
        }

        $street = mb_substr((string) ($filters['street'] ?? ''), 0, 100);

        if ($street !== '') {
            $parts[] = 'j.street LIKE :street';
            $params['street'] = '%' . $street . '%';
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }
}
