<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\PartnerFeedEvent;
use App\Repository\PartnerFeedEventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gera o feed CIFS JSON de cada parceiro e integra a Reverse Geocoding API do Waze.
 */
final class PartnerFeedService
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(
        private readonly PartnerFeedEventRepository $eventRepository,
        private readonly HttpClientInterface         $httpClient,
        private readonly LoggerInterface             $logger,
    ) {}

    // ── Feed JSON ─────────────────────────────────────────────────────────────

    /**
     * Retorna o array CIFS pronto para ser serializado como feed.json.
     *
     * Formato:
     * { "incidents": [ { "id": "...", "type": "...", ... } ] }
     */
    public function buildFeed(Partner $partner): array
    {
        $events = $this->eventRepository->findActiveByPartner($partner);

        return [
            'incidents' => array_map(
                fn (PartnerFeedEvent $e) => $this->toCifs($e),
                $events,
            ),
        ];
    }

    // ── Reverse Geocoding ─────────────────────────────────────────────────────

    /**
     * Consulta a Reverse Geocoding API do Waze e retorna a lista de ruas
     * dentro de 50 m das coordenadas fornecidas, ordenadas por distância.
     *
     * @return array<int, array{streetNames: string[], distance: float}>
     */
    public function reverseGeocode(
        Partner $partner,
        float $lat,
        float $lon,
    ): array {
        $token = $partner->getReverseGeocodingToken();

        if ($token === null || $token === '') {
            $this->logger->warning(
                'Partner {code} sem reverse geocoding token.',
                ['code' => $partner->getCode()],
            );

            return [];
        }

        try {
            $response = $this->httpClient->request('GET', $partner->getReverseGeocodingBaseUrl(), [
                'query'   => ['lat' => $lat, 'lon' => $lon, 'token' => $token],
                'timeout' => 5,
            ]);

            return $response->toArray()['result'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Reverse geocoding falhou para {code}: {msg}',
                ['code' => $partner->getCode(), 'msg' => $e->getMessage()],
            );

            return [];
        }
    }

    /**
     * Sugere o nome de rua mais próximo (via Reverse Geocoding),
     * persiste o JSON bruto no evento para auditoria e retorna a sugestão.
     */
    public function suggestStreetName(
        Partner $partner,
        PartnerFeedEvent $event,
    ): ?string {
        $lat = $event->getLatitude() !== null ? (float) $event->getLatitude() : null;
        $lon = $event->getLongitude() !== null ? (float) $event->getLongitude() : null;

        // Fallback: primeiro ponto da polyline
        if ($lat === null || $lon === null) {
            $point = $event->getFirstPointFromPolyline();
            if ($point === null) {
                return null;
            }

            [$lat, $lon] = [$point['lat'], $point['lon']];
        }

        $results = $this->reverseGeocode($partner, $lat, $lon);

        if (empty($results)) {
            return null;
        }

        $event->setGeocodingResult($results);

        return $results[0]['streetNames'][0] ?? null;
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    /** Serializa um PartnerFeedEvent para o formato CIFS JSON. */
    private function toCifs(PartnerFeedEvent $e): array
    {
        $tz  = new \DateTimeZone(self::TZ);
        $fmt = 'Y-m-d\TH:i:sP';

        $data = [
            'id'           => $e->getIncidentId(),
            'type'         => $e->getType(),
            'street'       => $e->getStreet(),
            'polyline'     => $e->getPolyline(),
            'direction'    => $e->getDirection(),
            'starttime'    => $e->getStarttime()->setTimezone($tz)->format($fmt),
            'creationtime' => $e->getCreatedAt()->setTimezone($tz)->format($fmt),
        ];

        if ($e->getSubtype() !== null) {
            $data['subtype'] = $e->getSubtype();
        }

        if ($e->getEndtime() !== null) {
            $data['endtime'] = $e->getEndtime()->setTimezone($tz)->format($fmt);
        }

        if ($e->getDescription() !== null) {
            $data['description'] = $e->getDescription();
        }

        if ($e->getUpdatedAt() !== null) {
            $data['updatetime'] = $e->getUpdatedAt()->setTimezone($tz)->format($fmt);
        }

        return $data;
    }
}
