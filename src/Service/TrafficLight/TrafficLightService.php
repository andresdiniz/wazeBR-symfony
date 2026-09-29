<?php

declare(strict_types=1);

namespace App\Service\TrafficLight;

use App\Dto\TrafficLight\TrafficLightCommand;
use App\Dto\TrafficLight\TrafficLightState;
use App\Service\TrafficLight\Exception\TrafficLightException;
use Psr\Log\LoggerInterface;

final class TrafficLightService
{
    /** @param iterable<TrafficLightConnectorInterface> $connectors */
    public function __construct(
        private readonly iterable $connectors,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string,mixed> $options */
    public function read(string $protocol, string $endpoint, array $options = []): TrafficLightState
    {
        $connector = $this->resolve($protocol);

        $this->logger->info('TrafficLight read', [
            'protocol' => $protocol,
            'endpoint' => $endpoint,
        ]);

        try {
            $state = $connector->read($endpoint, $options);
        } catch (\Throwable $e) {
            $this->logger->error('TrafficLight read failed', [
                'protocol' => $protocol,
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);
            throw new TrafficLightException(
                sprintf('Falha ao ler controlador [%s] %s: %s', $protocol, $endpoint, $e->getMessage()),
                0,
                $e,
            );
        }

        $this->logger->debug('TrafficLight state', [
            'controllerId' => $state->controllerId,
            'currentPhase' => $state->currentPhase,
            'faults'       => count($state->faults),
        ]);

        return $state;
    }

    /** @param array<string,mixed> $options */
    public function write(
        string $protocol,
        string $endpoint,
        TrafficLightCommand $command,
        array $options = [],
    ): bool {
        $connector = $this->resolve($protocol);

        $this->logger->warning('TrafficLight write', [
            'protocol' => $protocol,
            'endpoint' => $endpoint,
            'command'  => $command->type,
            'reason'   => $command->reason,
        ]);

        try {
            return $connector->write($endpoint, $command, $options);
        } catch (\Throwable $e) {
            $this->logger->error('TrafficLight write failed', [
                'protocol' => $protocol,
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);
            throw new TrafficLightException(
                sprintf('Falha ao escrever em controlador [%s] %s: %s', $protocol, $endpoint, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    private function resolve(string $protocol): TrafficLightConnectorInterface
    {
        foreach ($this->connectors as $connector) {
            if ($connector->supports($protocol)) {
                return $connector;
            }
        }

        throw new TrafficLightException(sprintf("Nenhum conector registrado para protocolo '%s'.", $protocol));
    }
}
