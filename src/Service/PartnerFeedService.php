<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Repository\PartnerFeedEventRepository;

final class PartnerFeedService
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function __construct(
        private readonly PartnerFeedEventRepository $eventRepository,
    ) {}

    public function buildFeed(Partner $partner): array
    {
        $events = $this->eventRepository->findActiveByPartner($partner);

        return [
            'incidents' => array_map(
                static fn ($event): array => $event->toCifsArray(),
                $events,
            ),
        ];
    }

    public function buildJson(Partner $partner): string
    {
        return json_encode(
            $this->buildFeed($partner),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
    }
}
