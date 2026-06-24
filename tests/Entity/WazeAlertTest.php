<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WazeAlert;
use PHPUnit\Framework\TestCase;

class WazeAlertTest extends TestCase
{
    public function testSettersAndGetters(): void
    {
        $alert = (new WazeAlert())
            ->setWazeId('test-id')
            ->setType('ACCIDENT')
            ->setSubtype('ACCIDENT_MAJOR')
            ->setLatitude(-19.92)
            ->setLongitude(-43.93)
            ->setStreet('Av. Afonso Pena')
            ->setCity('Belo Horizonte')
            ->setCountry('BR')
            ->setReliability(8)
            ->setConfidence(7)
            ->setReportRating(4)
            ->setPubMillis(1_700_000_000_000);

        $this->assertSame('test-id', $alert->getWazeId());
        $this->assertSame('ACCIDENT', $alert->getType());
        $this->assertSame('ACCIDENT_MAJOR', $alert->getSubtype());
        $this->assertSame(-19.92, $alert->getLatitude());
        $this->assertSame(-43.93, $alert->getLongitude());
        $this->assertSame('Av. Afonso Pena', $alert->getStreet());
        $this->assertSame('Belo Horizonte', $alert->getCity());
        $this->assertSame('BR', $alert->getCountry());
        $this->assertSame(8, $alert->getReliability());
        $this->assertSame(7, $alert->getConfidence());
        $this->assertSame(4, $alert->getReportRating());
        $this->assertSame(1_700_000_000_000, $alert->getPubMillis());
    }

    public function testCreatedAtIsSetOnConstruct(): void
    {
        $alert = new WazeAlert();
        $this->assertInstanceOf(\DateTimeImmutable::class, $alert->getCreatedAt());
    }
}
