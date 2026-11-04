<?php

declare(strict_types=1);
namespace App\Tests\Service;
use App\Service\JamForecastInputPolicy;
use PHPUnit\Framework\TestCase;
final class JamForecastInputPolicyTest extends TestCase
{
    public function testInterdictionsAreRemovedWithoutClosingThemAsJams(): void
    {
        $state=['open'=>[1=>['jam_id'=>1,'blocked'=>true],2=>['jam_id'=>2,'blocked'=>false],3=>['jam_id'=>3,'blocked'=>false]],'history'=>[['jam_id'=>1,'blocked'=>true],['jam_id'=>3,'blocked'=>false]],'items'=>[['level'=>5]]];
        $rows=[['id'=>1,'level'=>5,'blocking_alert_uuid'=>null],['id'=>2,'level'=>4,'blocking_alert_uuid'=>'block'],['id'=>3,'level'=>3,'blocking_alert_uuid'=>null]];
        [$clean,$eligible,$excluded]=(new JamForecastInputPolicy())->prepare($state,$rows);
        self::assertSame(2,$excluded);self::assertSame([3],array_column($eligible,'id'));
        self::assertSame([3],array_keys($clean['open']));self::assertSame([3],array_column($clean['history'],'jam_id'));self::assertSame([],$clean['items']);
    }
}
