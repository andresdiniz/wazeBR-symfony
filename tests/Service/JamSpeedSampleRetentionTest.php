<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JamDurationModel;
use PHPUnit\Framework\TestCase;

final class JamSpeedSampleRetentionTest extends TestCase
{
    public function testMoreThanThirtySamplesSurviveAndDuplicateReadingsAreIgnored(): void
    {
        $model = new JamDurationModel();
        $state = [];
        $start = 1700000000;
        for ($i = 0; $i < 35; ++$i) {
            $at = $start + $i * 60;
            $state = $model->update($state, [$this->row($at)], $at, 900);
        }
        $samples = $state['open'][1]['samples'];
        self::assertCount(35, $samples);
        self::assertSame($start, $samples[0]['at']);
        self::assertSame($at, $samples[34]['at']);
        self::assertSame(20.0, $samples[0]['speed']);

        $state = $model->update($state, [$this->row($at)], $at + 1, 900);
        self::assertSame($samples, $state['open'][1]['samples']);

        $row = $this->row($at);
        $row['is_active'] = 0;
        $row['deactivated_at'] = gmdate('Y-m-d H:i:s', $at + 60);
        $state = $model->update($state, [$row], $at + 60, 900);
        self::assertArrayNotHasKey(1, $state['open']);
        self::assertCount(1, $state['history']);
        self::assertSame($samples, $state['history'][0]['samples']);
        self::assertTrue($state['history'][0]['event']);
    }

    public function testLegacyMissingSamplesAreNotInvented(): void
    {
        $model = new JamDurationModel();
        $start = 1700000000;
        $state = $model->update([], [$this->row($start)], $start, 900);
        $state['open'][1]['start'] = $start - 3600;
        $state = $model->update($state, [$this->row($start + 60)], $start + 60, 900);
        self::assertSame($start - 3600, $state['open'][1]['start']);
        self::assertCount(2, $state['open'][1]['samples']);
        self::assertSame($start, $state['open'][1]['samples'][0]['at']);
    }

    private function row(int $at): array
    {
        return [
            'id' => 1, 'street' => 'Rua A', 'city' => 'Cidade B',
            'road_type' => 1, 'line' => [], 'blocking_alert_uuid' => null,
            'speed_kmh' => 20, 'level' => 3, 'length' => 1000, 'delay' => 120,
            'is_active' => 1, 'last_seen_at' => gmdate('Y-m-d H:i:s', $at),
            'deactivated_at' => null,
        ];
    }
}
