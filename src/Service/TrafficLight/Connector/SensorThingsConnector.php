<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Connector;

use App\Dto\TrafficLight\SignalPhase;
use App\Dto\TrafficLight\TrafficLightCommand;
use App\Dto\TrafficLight\TrafficLightFault;
use App\Dto\TrafficLight\TrafficLightState;
use App\Service\TrafficLight\Exception\TrafficLightException;
use App\Service\TrafficLight\TrafficLightConnectorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Conector OGC SensorThings API.
 *
 * Implementação de referência: Hamburg Traffic Lights (TLD)
 *   https://tld.iot.hamburg.de/v1.1
 *
 * Endpoint esperado (uma das duas formas):
 *   - Thing completo:      https://tld.iot.hamburg.de/v1.1/Things(12345)
 *   - Datastream direto:   https://tld.iot.hamburg.de/v1.1/Datastreams(67890)
 *
 * Options suportadas:
 *   - datastreamName (string, padrão 'primary_signal')
 *       Nome do Datastream dentro do Thing a consultar.
 *   - timeout (float, padrão 5.0)
 *   - headers (array)
 *
 * A API é SOMENTE LEITURA. write() lança TrafficLightException.
 */
final class SensorThingsConnector implements TrafficLightConnectorInterface
{
    private const PROTOCOL = 'SENSORTHINGS';

    /**
     * Mapa de códigos de estado da Hamburg → cor universal.
     * Fonte: documentação oficial dos dados abertos de Hamburgo.
     */
    private const COLOR_MAP = [
        0 => SignalPhase::COLOR_OFF,     // dark
        1 => SignalPhase::COLOR_RED,     // red
        2 => SignalPhase::COLOR_YELLOW,  // amber
        3 => SignalPhase::COLOR_GREEN,   // green
        4 => SignalPhase::COLOR_YELLOW,  // red + amber
        5 => SignalPhase::COLOR_YELLOW,  // flashing amber
        6 => SignalPhase::COLOR_GREEN,   // flashing green
        9 => SignalPhase::COLOR_OFF,     // unknown
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function supports(string $protocol): bool
    {
        return strtoupper($protocol) === self::PROTOCOL;
    }

    public function read(string $endpoint, array $options = []): TrafficLightState
    {
        $timeout        = (float) ($options['timeout'] ?? 5.0);
        $headers        = (array) ($options['headers'] ?? []);
        $datastreamName = (string) ($options['datastreamName'] ?? 'primary_signal');

        $isDatastreamUrl = str_contains($endpoint, '/Datastreams(');
        $isThingUrl      = str_contains($endpoint, '/Things(');

        if (!$isDatastreamUrl && !$isThingUrl) {
            throw new TrafficLightException(
                'Endpoint inválido. Use uma URL de Thing ou Datastream do SensorThings, '.
                'ex.: https://tld.iot.hamburg.de/v1.1/Things(12345)',
            );
        }

        // ── 1. Resolver Datastream ───────────────────────────────────────
        if ($isDatastreamUrl) {
            $datastream = $this->fetchDatastream($endpoint, $timeout, $headers);
        } else {
            $datastream = $this->resolveDatastreamFromThing(
                $endpoint,
                $datastreamName,
                $timeout,
                $headers,
            );
        }

        $datastreamId   = $datastream['@iot.id'] ?? null;
        $datastreamUnit = $datastream['unitOfMeasurement']['symbol'] ?? '';
        $datastreamDesc = $datastream['description'] ?? '';

        if ($datastreamId === null) {
            throw new TrafficLightException('Datastream sem @iot.id.');
        }

        // ── 2. Buscar a Observation mais recente ─────────────────────────
        $obsUrl = sprintf(
            '%s/Observations?$orderby=phenomenonTime%%20desc&$top=1',
            $this->stripQuery($datastream['@iot.selfLink'] ?? $endpoint),
        );

        // Fallback se o selfLink não existir
        if (!isset($datastream['@iot.selfLink'])) {
            $base = $this->baseFromDatastreamUrl($endpoint, $datastreamId);
            $obsUrl = $base . '/Observations?$orderby=phenomenonTime%20desc&$top=1';
        }

        try {
            $response = $this->httpClient->request('GET', $obsUrl, [
                'timeout' => $timeout,
                'headers' => $headers,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new TrafficLightException(
                sprintf('Falha ao consultar Observations: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        $observation = $data['value'][0] ?? null;
        if ($observation === null) {
            // Sem leitura ainda — devolve estado neutro
            return new TrafficLightState(
                controllerId: $endpoint,
                protocol:     self::PROTOCOL,
                readAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                currentPhase: null,
                cycleSeconds: null,
                mode:         TrafficLightState::MODE_UNKNOWN,
                phases:       [],
                detectors:    [],
                faults:       [],
                raw: [
                    'datastreamId'   => $datastreamId,
                    'datastreamName' => $datastream['name'] ?? null,
                    'description'    => $datastreamDesc,
                    'unit'           => $datastreamUnit,
                    'observation'    => null,
                    'message'        => 'Sem observações recentes.',
                ],
            );
        }

        $resultValue = $observation['result'] ?? null;
        $colorCode   = is_numeric($resultValue) ? (int) $resultValue : 9;
        $color       = self::COLOR_MAP[$colorCode] ?? SignalPhase::COLOR_OFF;

        $phenomenonTime = $observation['phenomenonTime'] ?? null;
        $readAt = $phenomenonTime !== null
            ? new \DateTimeImmutable($phenomenonTime)
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $phases = [
            new SignalPhase(
                number: 1,
                color:  $color,
                pedestrian: false,
            ),
        ];

        $mode = match ($colorCode) {
            5, 6    => TrafficLightState::MODE_FLASH,
            0       => TrafficLightState::MODE_MANUAL,
            default => TrafficLightState::MODE_AUTOMATIC,
        };

        return new TrafficLightState(
            controllerId: $endpoint,
            protocol:     self::PROTOCOL,
            readAt:       $readAt,
            currentPhase: 1,
            cycleSeconds: null,
            mode:         $mode,
            phases:       $phases,
            detectors:    [],
            faults:       [],
            raw: [
                'datastreamId'    => $datastreamId,
                'datastreamName'  => $datastream['name'] ?? null,
                'description'     => $datastreamDesc,
                'unit'            => $datastreamUnit,
                'colorCode'       => $colorCode,
                'phenomenonTime'  => $phenomenonTime,
                'resultTime'      => $observation['resultTime'] ?? null,
                'observationId'   => $observation['@iot.id'] ?? null,
            ],
        );
    }

    public function write(
        string $endpoint,
        TrafficLightCommand $command,
        array $options = [],
    ): bool {
        throw new TrafficLightException(
            'A API SensorThings (Hamburg) é somente leitura. '.
            'Comandos de escrita não são suportados.',
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function fetchDatastream(string $url, float $timeout, array $headers): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => $timeout,
                'headers' => $headers,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new TrafficLightException(
                sprintf('Falha ao buscar Datastream: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        if (!isset($data['@iot.id'])) {
            throw new TrafficLightException('Resposta não é um Datastream válido.');
        }

        return $data;
    }

    /**
     * Consulta o Thing com $expand=Datastreams e retorna o Datastream
     * cujo name == $datastreamName (ou o primeiro se nenhum casar).
     *
     * @return array<string,mixed>
     */
    private function resolveDatastreamFromThing(
        string $thingUrl,
        string $datastreamName,
        float $timeout,
        array $headers,
    ): array {
        $separator = str_contains($thingUrl, '?') ? '&' : '?';
        $url = $thingUrl . $separator . '$expand=Datastreams';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => $timeout,
                'headers' => $headers,
            ]);
            $thing = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new TrafficLightException(
                sprintf('Falha ao buscar Thing: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        $streams = $thing['Datastreams'] ?? [];
        if (empty($streams)) {
            throw new TrafficLightException('Thing não possui Datastreams.');
        }

        // Match exato por nome
        foreach ($streams as $stream) {
            if (($stream['name'] ?? null) === $datastreamName) {
                return $stream;
            }
        }

        // Match por contains (fallback)
        foreach ($streams as $stream) {
            $name = (string) ($stream['name'] ?? '');
            if ($name !== '' && stripos($name, $datastreamName) !== false) {
                return $stream;
            }
        }

        // Último recurso: primeiro Datastream
        return $streams[0];
    }

    private function stripQuery(string $url): string
    {
        $pos = strpos($url, '?');
        return $pos === false ? $url : substr($url, 0, $pos);
    }

    private function baseFromDatastreamUrl(string $endpoint, mixed $datastreamId): string
    {
        // Extrai base antes de /Datastreams(...)
        $pos = strpos($endpoint, '/Datastreams(');
        if ($pos === false) {
            return rtrim($endpoint, '/');
        }
        return substr($endpoint, 0, $pos) . '/Datastreams(' . $datastreamId . ')';
    }
}
