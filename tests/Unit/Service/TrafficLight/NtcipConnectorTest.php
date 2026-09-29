<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\TrafficLight;

use App\Service\TrafficLight\Connector\NtcipConnector;
use App\Service\TrafficLight\Profile\VendorProfileRegistry;
use PHPUnit\Framework\TestCase;

final class NtcipConnectorTest extends TestCase
{
    private NtcipConnector $connector;

    protected function setUp(): void
    {
        $this->connector = new NtcipConnector(new VendorProfileRegistry([]));
    }

    /**
     * @dataProvider octetProvider
     * @param array<int,bool> $expected
     */
    public function testOctetStringToBitArray(string $input, array $expected): void
    {
        $method = new \ReflectionMethod(NtcipConnector::class, 'octetStringToBitArray');
        $method->setAccessible(true);

        $result = $method->invoke($this->connector, $input);
        $result = array_filter($result);
        self::assertSame($expected, $result);
    }

    /** @return array<string, array{string, array<int,bool>}> */
    public static function octetProvider(): array
    {
        return [
            'byte 0x01 → fase 1 ativa'       => ["\x01", [1 => true]],
            'byte 0x02 → fase 2 ativa'       => ["\x02", [2 => true]],
            'byte 0x05 → fases 1 e 3'        => ["\x05", [1 => true, 3 => true]],
            'dois bytes 0x00 0x01 → fase 9'  => ["\x00\x01", [9 => true]],
        ];
    }

    public function testSupports(): void
    {
        self::assertTrue($this->connector->supports('NTCIP'));
        self::assertTrue($this->connector->supports('ntcip'));
        self::assertFalse($this->connector->supports('MODBUS_TCP'));
    }
}
