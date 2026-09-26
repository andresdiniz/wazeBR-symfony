<?php

declare(strict_types=1);

namespace App\Message;

final readonly class PollTrafficLight
{
    public function __construct(public int $trafficLightId) {}
}
