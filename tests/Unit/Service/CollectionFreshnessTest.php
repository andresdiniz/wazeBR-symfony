<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CollectionFreshness;
use PHPUnit\Framework\TestCase;

final class CollectionFreshnessTest extends TestCase
{
    public function testStatesAndBoundary(): void
    {
        $service = new CollectionFreshness();
        $now = new \DateTimeImmutable('2026-01-01T12:00:00Z');
        self::assertSame('no_record', $service->evaluate(null, 60, true, $now)['status']);
        self::assertSame('disabled', $service->evaluate(null, 60, false, $now)['status']);
        self::assertSame('clock_skew', $service->evaluate($now->modify('+1 second'), 60, true, $now)['status']);
        self::assertSame('current', $service->evaluate($now->modify('-120 seconds'), 60, true, $now)['status']);
        $late = $service->evaluate($now->modify('-121 seconds'), 60, true, $now);
        self::assertSame('late', $late['status']);
        self::assertSame(121, $late['age_seconds']);
        self::assertSame(1, $service->evaluate(null, 0, true, $now)['expected_interval_seconds']);
    }
}
