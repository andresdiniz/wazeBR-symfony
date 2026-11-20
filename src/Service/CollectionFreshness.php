<?php

declare(strict_types=1);

namespace App\Service;

final class CollectionFreshness
{
    public function __construct(
        private readonly int $schedulerCadenceSeconds = 120,
        private readonly int $executionGraceSeconds = 60,
    ) {}

    /** @return array{status: string, age_seconds: ?int, last_recorded_at: ?string, expected_interval_seconds: int, late_after_seconds: int} */
    public function evaluate(?\DateTimeImmutable $lastRecordedAt, int $interval, bool $enabled, \DateTimeImmutable $now): array
    {
        $interval = max(1, $interval);
        $cadence = max(1, $this->schedulerCadenceSeconds);
        $threshold = $interval + $cadence + max(0, $this->executionGraceSeconds);
        $age = $lastRecordedAt === null ? null : $now->getTimestamp() - $lastRecordedAt->getTimestamp();
        $status = match (true) {
            !$enabled => 'disabled',
            $age === null => 'no_record',
            $age < 0 => 'clock_skew',
            $age > $threshold => 'late',
            default => 'current',
        };
        return [
            'status' => $status,
            'age_seconds' => $age,
            'last_recorded_at' => $lastRecordedAt?->format(DATE_ATOM),
            'expected_interval_seconds' => $interval,
            'late_after_seconds' => $threshold,
        ];
    }
}
