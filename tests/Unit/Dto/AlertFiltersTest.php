<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\AlertFilters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AlertFiltersTest extends TestCase
{
    public function testDefaults(): void
    {
        self::assertSame([
            'period' => 'all', 'type' => '', 'subtype' => '', 'city' => '',
            'query' => '', 'min_confidence' => 0, 'active' => false,
        ], AlertFilters::fromRequest(new Request())->toArray());
    }

    public function testNormalizesValues(): void
    {
        $filters = AlertFilters::fromRequest(new Request([
            'period' => 'invalid', 'type' => ' HAZARD ', 'city' => ' Cidade ',
            'min_confidence' => '99', 'active' => 'false',
        ]))->toArray();
        self::assertSame('all', $filters['period']);
        self::assertSame('HAZARD', $filters['type']);
        self::assertSame('Cidade', $filters['city']);
        self::assertSame(5, $filters['min_confidence']);
        self::assertFalse($filters['active']);
    }

    public function testActiveAndInvalidConfidence(): void
    {
        $filters = AlertFilters::fromRequest(new Request([
            'period' => 'today', 'active' => '1', 'min_confidence' => 'invalid',
        ]))->toArray();
        self::assertTrue($filters['active']);
        self::assertSame('today', $filters['period']);
        self::assertSame(0, $filters['min_confidence']);
    }
}
