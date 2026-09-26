<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Connector;

use App\Dto\TrafficLight\SignalPhase;
use App\Dto\TrafficLight\TrafficLightCommand;
use App\Dto\TrafficLight\TrafficLightState;
use App\Service\TrafficLight\Exception\TrafficLightException;
use App\Service\TrafficLight\TrafficLightConnectorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpRestConnector implements TrafficLightConnectorInterface
{
    private const PROTOCOL = 'HTTP_REST';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function supports(string $protocol): bool
    {
        return strtoupper($protocol) === self::PROTOCOL;
    }

    public function read(string $endpoint, array $options = []): TrafficLightState
    {
        $statePath = (string) ($options['statePath'] ?? '/state');
        $url = rtrim($endpoint, '/') . $statePath;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => (float) ($options['timeout'] ?? 5.0),
                'headers' => (array) ($options['headers'] ?? []),
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new TrafficLightException("HTTP REST falhou: {$e->getMessage()}", 0, $e);
        }

        $phases = array_map(
            static fn (array $p): SignalPhase => new SignalPhase(
                number:           (int) ($p['number'] ?? 0),
                color:            strtoupper((string) ($p['color'] ?? SignalPhase::COLOR_OFF)),
                remainingSeconds: isset($p['remainingSeconds']) ? (int) $p['remainingSeconds'] : null,
                pedestrian:       (bool) ($p['pedestrian'] ?? false),
            ),
            (array) ($data['phases'] ?? []),
        );

        return new TrafficLightState(
            controllerId: sprintf('%s', $endpoint),
            protocol:     self::PROTOCOL,
            readAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            currentPhase: isset($data['currentPhase']) ? (int) $data['currentPhase'] : null,
            cycleSeconds: isset($data['cycleSeconds']) ? (int) $data['cycleSeconds'] : null,
            mode:         (string) ($data['mode'] ?? TrafficLightState::MODE_UNKNOWN),
            phases:       $phases,
            detectors:    [],
            faults:       [],
            raw:          $data,
        );
    }

    public function write(
        string $endpoint,
        TrafficLightCommand $command,
        array $options = [],
    ): bool {
        $commandPath = (string) ($options['commandPath'] ?? '/command');
        $url = rtrim($endpoint, '/') . $commandPath;

        try {
            $response = $this->httpClient->request('POST', $url, [
                'timeout' => (float) ($options['timeout'] ?? 5.0),
                'headers' => array_merge(
                    ['Content-Type' => 'application/json'],
                    (array) ($options['headers'] ?? []),
                ),
                'json' => [
                    'type'    => $command->type,
                    'payload' => $command->payload,
                    'reason'  => $command->reason,
                ],
            ]);

            return $response->getStatusCode() < 400;
        } catch (\Throwable $e) {
            throw new TrafficLightException("HTTP REST write falhou: {$e->getMessage()}", 0, $e);
        }
    }
}
