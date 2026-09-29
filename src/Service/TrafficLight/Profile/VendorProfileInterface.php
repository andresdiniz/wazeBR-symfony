<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Profile;

interface VendorProfileInterface
{
    public function getName(): string;
    public function getProtocol(): string;

    /** @return array<string,string> */
    public function getOids(): array;

    /** @return array<string,int|string> */
    public function getRegisters(): array;
}
