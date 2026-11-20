<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

final readonly class AlertFilters
{
    /** @param array<string, string|int|bool> $values */
    private function __construct(private array $values) {}

    public static function fromRequest(Request $request): self
    {
        $period = trim((string) $request->query->get('period', 'all'));
        $confidence = filter_var($request->query->get('min_confidence', 0), FILTER_VALIDATE_INT);

        return new self([
            'period' => in_array($period, ['all', 'today', 'week', 'month', 'year'], true) ? $period : 'all',
            'type' => trim((string) $request->query->get('type', '')),
            'subtype' => trim((string) $request->query->get('subtype', '')),
            'city' => trim((string) $request->query->get('city', '')),
            'query' => trim((string) $request->query->get('query', '')),
            'min_confidence' => max(0, min(5, $confidence === false ? 0 : $confidence)),
            'active' => $request->query->getBoolean('active', false),
        ]);
    }

    /** @return array<string, string|int|bool> */
    public function toArray(): array
    {
        return $this->values;
    }
}
