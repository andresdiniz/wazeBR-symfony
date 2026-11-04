<?php

declare(strict_types=1);
namespace App\Tests\Service;
use App\Service\JamDurationModel;
use PHPUnit\Framework\TestCase;
final class JamDurationModelTest extends TestCase
{
    public function testInsufficientHistoryDoesNotInventTime(): void
    {self::assertNull((new JamDurationModel())->estimate([],600)['median']);}
    public function testConditionsOnElapsedTime(): void
    {
        $pool=[];for($i=0;$i<20;$i++)$pool[]=['duration'=>1200,'event'=>true];
        $result=(new JamDurationModel())->estimate($pool,600);
        self::assertSame(600,$result['median']);self::assertSame(20,$result['at_risk']);
        self::assertNull((new JamDurationModel())->estimate($pool,1200)['median']);
    }
    public function testCensoredRecordsDoNotBecomeEvents(): void
    {
        $pool=array_fill(0,30,['duration'=>3600,'event'=>false]);
        self::assertNull((new JamDurationModel())->estimate($pool,600)['median']);
    }
}
