<?php

declare(strict_types=1);
namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class OptionalIntegerFilter
{
    public static function parse(mixed $value, int $default, int $minimum, string $name): int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) return $default;
        if (!is_string($value) && !is_int($value)) throw new BadRequestHttpException(sprintf('O filtro %s deve ser um número inteiro.', $name));
        $number = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => PHP_INT_MAX]]);
        if ($number === false) throw new BadRequestHttpException(sprintf('O filtro %s deve ser um inteiro maior ou igual a %d.', $name, $minimum));
        return $number;
    }
}
