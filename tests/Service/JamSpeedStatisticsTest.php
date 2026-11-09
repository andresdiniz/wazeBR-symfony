<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JamSpeedStatistics;
use PHPUnit\Framework\TestCase;

final class JamSpeedStatisticsTest extends TestCase
{
    public function testIrregularIntervalsUseTimeWeights(): void
    {
        $s = JamSpeedStatistics::summarize([
            ['at' => 1240, 'speed' => 10],
            ['at' => 1000, 'speed' => 20],
            ['at' => 1060, 'speed' => 40],
        ], 900);
        self::assertSame(35.0, $s['mean_speed_kmh']);
        self::assertSame(10.0, $s['min_speed_kmh']);
        self::assertSame(40.0, $s['max_speed_kmh']);
        self::assertSame(240, $s['covered_seconds']);
        self::assertSame(1.0, $s['coverage_ratio']);
    }

    public function testZeroIsValidAndLargeGapsAreExcluded(): void
    {
        $s = JamSpeedStatistics::summarize([
            ['at' => 1000, 'speed' => 0],
            ['at' => 1060, 'speed' => 20],
            ['at' => 2300, 'speed' => 40],
        ], 900);
        self::assertSame(0.0, $s['mean_speed_kmh']);
        self::assertSame(60, $s['covered_seconds']);
        self::assertSame(1240, $s['uncovered_seconds']);
    }

    public function testInvalidValuesBreakCoverageAndDuplicateTimesUseLastValue(): void
    {
        $s = JamSpeedStatistics::summarize([
            ['at' => 1000, 'speed' => 10],
            ['at' => 1060, 'speed' => null],
            ['at' => 1120, 'speed' => 20],
            ['at' => 1120, 'speed' => 30],
            ['at' => 1180, 'speed' => 40],
            ['at' => 1240, 'speed' => -1],
        ], 900);
        self::assertSame(5, $s['sample_count']);
        self::assertSame(3, $s['valid_sample_count']);
        self::assertSame(60, $s['covered_seconds']);
        self::assertSame(30.0, $s['mean_speed_kmh']);
        self::assertNull($s['last_speed_kmh']);
    }

    public function testEmptyAndSingleSampleHaveNoMean(): void
    {
        self::assertNull(JamSpeedStatistics::summarize([], 900)['mean_speed_kmh']);
        $s = JamSpeedStatistics::summarize([['at' => 1000, 'speed' => 0]], 900);
        self::assertNull($s['mean_speed_kmh']);
        self::assertSame(0.0, $s['min_speed_kmh']);
        self::assertNull($s['coverage_ratio']);
    }

    public function testEpisodeCoverageDoesNotInventMissingPrefixOrTail(): void
    {
        $s = JamSpeedStatistics::summarize([
            ['at' => 1000, 'speed' => 20], ['at' => 1060, 'speed' => 40],
        ], 900, 940, 1120);
        self::assertSame(60, $s['covered_seconds']);
        self::assertSame(120, $s['uncovered_seconds']);
        self::assertSame(20.0, $s['mean_speed_kmh']);
    }

    public function testStateEnrichmentIncludesOpenClosedAndVisibleItems(): void
    {
        $episode = ['start' => 1000, 'last' => 1060, 'samples' => [
            ['at' => 1000, 'speed' => 20], ['at' => 1060, 'speed' => 40],
        ]];
        $state = JamSpeedStatistics::enrichState([
            'gap_seconds' => 900, 'open' => [1 => $episode], 'history' => [$episode],
            'items' => [['jam_id' => 1], ['jam_id' => 2]],
        ]);
        self::assertSame(20.0, $state['open'][1]['speed_statistics']['mean_speed_kmh']);
        self::assertSame($state['open'][1]['speed_statistics'], $state['history'][0]['speed_statistics']);
        self::assertSame($state['open'][1]['speed_statistics'], $state['items'][0]['speed_statistics']);
        self::assertNull($state['items'][1]['speed_statistics']);
    }
}
