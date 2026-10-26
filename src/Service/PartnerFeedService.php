<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;

/**
 * Fachada fina sobre o CifsFeedBuilder: mantém a API usada por controller,
 * templates e comando, mas o payload vem de um único lugar.
 */
final class PartnerFeedService
{
    public function __construct(
        private readonly CifsFeedBuilder $feedBuilder,
    ) {}

    /** @return array{incidents: list<array<string, string>>} */
    public function buildFeed(Partner $partner): array
    {
        return $this->feedBuilder->buildJsonPayload($partner);
    }

    public function buildJson(Partner $partner, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($this->buildFeed($partner), $flags);
    }

    public function buildXml(Partner $partner): string
    {
        return $this->feedBuilder->buildXmlPayload($partner);
    }
}
