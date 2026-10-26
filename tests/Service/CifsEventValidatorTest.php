<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\PartnerFeedEvent;
use App\Service\CifsEventValidator;
use App\Service\CifsFeedBuilder;
use PHPUnit\Framework\TestCase;

final class CifsEventValidatorTest extends TestCase
{
    private function event(array $o = []): PartnerFeedEvent
    {
        $e = (new PartnerFeedEvent())
            ->setCifsType($o['type'] ?? 'HAZARD')
            ->setCifsSubtype($o['subtype'] ?? 'HAZARD_ON_ROAD_CONSTRUCTION')
            ->setStreet($o['street'] ?? 'Av. Teste')
            ->setPolyline($o['polyline'] ?? [[-20.66, -43.78], [-20.67, -43.79]])
            ->setDirection($o['direction'] ?? 'BOTH_DIRECTIONS')
            ->setEndTime($o['end'] ?? new \DateTimeImmutable('+1 day'));

        return $e;
    }

    public function testValidEventHasNoErrors(): void
    {
        $r = (new CifsEventValidator())->validate($this->event());
        self::assertSame([], $r['errors']);
    }

    public function testSubtypeMustMatchType(): void
    {
        $r = (new CifsEventValidator())->validate($this->event(['type' => 'JAM', 'subtype' => 'HAZARD_ON_ROAD']));
        self::assertNotEmpty($r['errors']);
    }

    public function testInventedSubtypeWithValidPrefixIsRejected(): void
    {
        self::assertFalse(PartnerFeedEvent::isValidSubtype('HAZARD', 'HAZARD_FOO'));
    }

    public function testStreetRequired(): void
    {
        $r = (new CifsEventValidator())->validate($this->event(['street' => '  ']));
        self::assertNotEmpty($r['errors']);
    }

    public function testSinglePointOneDirectionRejected(): void
    {
        $r = (new CifsEventValidator())->validate($this->event(['polyline' => [[-20.6, -43.7]], 'direction' => 'ONE_DIRECTION']));
        self::assertNotEmpty($r['errors']);
    }

    public function testEndBeforeStartRejected(): void
    {
        $e = $this->event(['end' => new \DateTimeImmutable('-2 days')]);
        self::assertNotEmpty((new CifsEventValidator())->validate($e)['errors']);
    }

    public function testCifsArrayOmitsEmptyAndUsesOffset(): void
    {
        $a = $this->event()->toCifsArray();
        self::assertArrayNotHasKey('description', $a);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d-03:00$/', $a['starttime']);
        self::assertSame('-20.660000 -43.780000 -20.670000 -43.790000', $a['polyline']);
    }

    public function testXmlUsesIdAttributeAndRootIncidents(): void
    {
        $xml = CifsFeedBuilder::renderXml([$this->event()->setStreet('Rua A & B')->toCifsArray()]);
        $doc = simplexml_load_string($xml);
        self::assertSame('incidents', $doc->getName());
        self::assertNotEmpty((string) $doc->incident['id']);
        self::assertSame('Rua A & B', (string) $doc->incident->street);
    }
}
