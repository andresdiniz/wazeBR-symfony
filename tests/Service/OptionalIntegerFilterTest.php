<?php

declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\OptionalIntegerFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class OptionalIntegerFilterTest extends TestCase
{
    #[DataProvider('validValues')]
    public function testValidValues(mixed $input, int $default, int $minimum, int $expected): void
    {
        self::assertSame($expected, OptionalIntegerFilter::parse($input, $default, $minimum, 'partner'));
    }
    public static function validValues(): array
    {
        return [[null,0,0,0],['',0,0,0],['  ',0,0,0],['0',0,0,0],['7',0,0,7],[' 7 ',0,0,7],[7,0,0,7],['',25,1,25],[null,1,1,1]];
    }
    #[DataProvider('invalidValues')]
    public function testInvalidValues(mixed $input): void
    {
        $this->expectException(BadRequestHttpException::class);
        OptionalIntegerFilter::parse($input, 0, 0, 'partner');
    }
    public static function invalidValues(): array
    {
        return [['abc'],['-1'],['1.5'],['1e3'],[true],[[]],[['7']],['99999999999999999999999999999999999999']];
    }
    public function testPageMustBePositive(): void
    {
        $this->expectException(BadRequestHttpException::class);
        OptionalIntegerFilter::parse('0', 1, 1, 'page');
    }
}
