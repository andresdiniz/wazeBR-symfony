<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ForecastHistoryWindows;
use PHPUnit\Framework\TestCase;

final class ForecastHistoryWindowsTest extends TestCase
{
    public function testMidnightUsesFullUtcDates(): void
    {
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $rows = ForecastHistoryWindows::build(new \DateTimeImmutable('2026-10-27 23:45:00', $tz), 15, 30, new \DateTimeImmutable('2026-10-27 20:00:00', $tz), $tz);
        self::assertSame(['start' => '2026-10-21 02:45:00', 'end' => '2026-10-21 03:00:00'], $rows[0]);
    }

    public function testSundayToMondayKeepsStartWeekday(): void
    {
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $rows = ForecastHistoryWindows::build(new \DateTimeImmutable('2026-11-01 23:45:00', $tz), 15, 14, new \DateTimeImmutable('2026-11-01 20:00:00', $tz), $tz);
        self::assertSame(['start' => '2026-10-26 02:45:00', 'end' => '2026-10-26 03:00:00'], $rows[0]);
    }

    public function testPartialAndFutureWindowsAreExcluded(): void
    {
        $tz = new \DateTimeZone('UTC');
        $target = new \DateTimeImmutable('2026-10-27 20:00:00', $tz);
        $rows = ForecastHistoryWindows::build($target, 15, 14, new \DateTimeImmutable('2026-10-27 20:05:00', $tz), $tz);
        self::assertSame('2026-10-20 20:00:00', $rows[0]['start']);
        foreach ($rows as $row) self::assertLessThanOrEqual('2026-10-27 20:05:00', $row['end']);
    }

    public function testConfiguredTimezoneIsNotFixedMinusThree(): void
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $rows = ForecastHistoryWindows::build(new \DateTimeImmutable('2026-10-27 10:00:00', $tz), 15, 14, new \DateTimeImmutable('2026-10-27 09:00:00', $tz), $tz);
        self::assertSame('2026-10-20 04:30:00', $rows[0]['start']);
        self::assertSame('2026-10-20 04:45:00', $rows[0]['end']);
    }

    public function testNoCompleteWindowProducesEmptyResult(): void
    {
        $tz = new \DateTimeZone('UTC');
        self::assertSame([], ForecastHistoryWindows::build(new \DateTimeImmutable('2026-10-27 23:45:00', $tz), 15, 1, new \DateTimeImmutable('2026-10-27 20:00:00', $tz), $tz));
    }

    public function testInvalidSlotIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ForecastHistoryWindows::validateSlot(7);
    }
}
