<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JamSpeedHistoryReader;
use PHPUnit\Framework\TestCase;

final class JamSpeedHistoryReaderTest extends TestCase
{
    public function testSelectsLatestMatchingEpisodeWithoutMerging(): void
    {
        $old = ['jam_id' => 1, 'start' => 1000, 'last' => 1060, 'samples' => [
            ['at' => 1000, 'speed' => 10], ['at' => 1060, 'speed' => 20],
        ]];
        $new = ['jam_id' => 1, 'start' => 2000, 'last' => 2060, 'samples' => [
            ['at' => 2060, 'speed' => 40], ['at' => 2000, 'speed' => 30],
        ]];
        $other = ['jam_id' => 2, 'last' => 9999];
        $result = JamSpeedHistoryReader::fromPayload([
            'gap_seconds' => 900, 'generated_at' => 2070,
            'history' => [$old, $other], 'open' => [1 => $new],
        ], 1);
        self::assertTrue($result['is_open']);
        self::assertSame(2, $result['statistics']['sample_count']);
        self::assertSame(30.0, $result['statistics']['mean_speed_kmh']);
        self::assertSame(2000, $result['samples'][0]['at']);
        self::assertSame(40.0, $result['samples'][0]['x']);
        self::assertSame(700.0, $result['samples'][1]['x']);
    }

    public function testMissingJamReturnsNullAndInvalidSpeedRemainsGap(): void
    {
        self::assertNull(JamSpeedHistoryReader::fromPayload([], 1));
        $result = JamSpeedHistoryReader::fromPayload(['history' => [[
            'jam_id' => 1, 'start' => 1000, 'last' => 1120,
            'samples' => [['at' => 1000, 'speed' => 0], ['at' => 1060, 'speed' => null], ['at' => 1120, 'speed' => 20]],
        ]]], 1);
        self::assertFalse($result['is_open']);
        self::assertNull($result['samples'][1]['y']);
        self::assertNull($result['statistics']['mean_speed_kmh']);
        self::assertSame(0, $result['statistics']['covered_seconds']);
    }
}
