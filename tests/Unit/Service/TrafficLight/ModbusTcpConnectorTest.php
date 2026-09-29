<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\TrafficLight;

use App\Service\TrafficLight\Connector\ModbusTcpConnector;
use App\Service\TrafficLight\Profile\VendorProfileRegistry;
use PHPUnit\Framework\TestCase;

final class ModbusTcpConnectorTest extends TestCase
{
    private ModbusTcpConnector $connector;

    protected function setUp(): void
    {
        $this->connector = new ModbusTcpConnector(new VendorProfileRegistry([]));
    }

    public function testFrameReadHoldingRegisters(): void
    {
        $method = new \ReflectionMethod(ModbusTcpConnector::class, 'frameReadHoldingRegisters');
        $method->setAccessible(true);

        $frame = $method->invoke($this->connector, 0x0001, 1, 0x0000, 16);

        self::assertSame(12, strlen($frame));
        self::assertSame("\x00\x01", substr($frame, 0, 2));
        self::assertSame("\x00\x00", substr($frame, 2, 2));
        self::assertSame("\x00\x06", substr($frame, 4, 2));
        self::assertSame("\x01", $frame[6]);
        self::assertSame("\x03", $frame[7]);
        self::assertSame("\x00\x00", substr($frame, 8, 2));
        self::assertSame("\x00\x10", substr($frame, 10, 2));
    }

    public function testFrameWriteSingleRegister(): void
    {
        $method = new \ReflectionMethod(ModbusTcpConnector::class, 'frameWriteSingleRegister');
        $method->setAccessible(true);

        $frame = $method->invoke($this->connector, 0x0002, 1, 0x0011, 2);

        self::assertSame("\x00\x02", substr($frame, 0, 2));
        self::assertSame("\x06", $frame[7]);
        self::assertSame("\x00\x11", substr($frame, 8, 2));
        self::assertSame("\x00\x02", substr($frame, 10, 2));
    }
}
