<?php

declare(strict_types=1);

namespace App\Service;

final class CollectionFreshness
{
    /** @return array{status: string, age_seconds: ?int, last_recorded_at: ?string, expected_interval_seconds: int} */
    public function evaluate(?\DateTimeImmutable $lastRecordedAt, int $interval, bool $enabled, \DateTimeImmutable $now): array
    {
        $interval = max(1, $interval);
        $age = $lastRecordedAt === null ? null : $now->getTimestamp() - $lastRecordedAt->getTimestamp();
        $status = match (true) {
            !$enabled => 'disabled',
            $age === null => 'no_record',
            $age < 0 => 'clock_skew',
            $age > $interval * 2 => 'late',
            default => 'current',
        };
        return [
            'status' => $status,
            'age_seconds' => $age,
            'last_recorded_at' => $lastRecordedAt?->format(DATE_ATOM),
            'expected_interval_seconds' => $interval,
        ];
    }
}
