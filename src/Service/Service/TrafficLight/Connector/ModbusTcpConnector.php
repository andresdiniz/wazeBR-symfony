<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Connector;

use App\Dto\TrafficLight\SignalPhase;
use App\Dto\TrafficLight\TrafficLightCommand;
use App\Dto\TrafficLight\TrafficLightState;
use App\Service\TrafficLight\Exception\TrafficLightException;
use App\Service\TrafficLight\TrafficLightConnectorInterface;

final class ModbusTcpConnector implements TrafficLightConnectorInterface
{
    private const PROTOCOL = 'MODBUS_TCP';

    public function supports(string $protocol): bool
    {
        return strtoupper($protocol) === self::PROTOCOL;
    }

    public function read(string $endpoint, array $options = []): TrafficLightState
    {
        [$host, $port] = $this->parseEndpoint($endpoint);
        $unitId        = (int) ($options['unitId'] ?? 1);
        $startAddress  = (int) ($options['startAddress'] ?? 0x0000);
        $quantity      = (int) ($options['quantity'] ?? 16);
        $timeout       = (float) ($options['timeout'] ?? 2.0);

        $socket = $this->open($host, $port, $timeout);

        try {
            $tx = random_int(1, 0xFFFF);
            $request = $this->frameReadHoldingRegisters($tx, $unitId, $startAddress, $quantity);
            fwrite($socket, $request);

            $response = $this->readResponse($socket);
            $registers = $this->parseReadResponse($response, $tx);
        } finally {
            fclose($socket);
        }

        return new TrafficLightState(
            controllerId: sprintf('%s:%d', $host, $port),
            protocol:     self::PROTOCOL,
            readAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            currentPhase: $registers[0] ?? null,
            cycleSeconds: $registers[1] ?? null,
            mode:         TrafficLightState::MODE_AUTOMATIC,
            phases:       $this->buildPhases($registers),
            raw:          ['registers' => $registers],
        );
    }

    public function write(
        string $endpoint,
        TrafficLightCommand $command,
        array $options = [],
    ): bool {
        [$host, $port] = $this->parseEndpoint($endpoint);
        $unitId        = (int) ($options['unitId'] ?? 1);
        $timeout       = (float) ($options['timeout'] ?? 2.0);

        $address = match ($command->type) {
            TrafficLightCommand::SET_MODE,
            TrafficLightCommand::FLASH_YELLOW  => (int) ($options['modeAddress'] ?? 0x0010),
            TrafficLightCommand::SET_PHASE,
            TrafficLightCommand::FORCE_GREEN,
            TrafficLightCommand::FORCE_RED     => (int) ($options['phaseAddress'] ?? 0x0011),
            TrafficLightCommand::CLEAR_FAULT   => (int) ($options['clearFaultAddress'] ?? 0x0012),
            default => throw new TrafficLightException("Comando não suportado em Modbus: {$command->type}"),
        };

        $value = match ($command->type) {
            TrafficLightCommand::SET_MODE     => $this->mapModeToRegister((string) ($command->payload['mode'] ?? 'AUTOMATIC')),
            TrafficLightCommand::FLASH_YELLOW => 6,
            TrafficLightCommand::FORCE_GREEN  => 1,
            TrafficLightCommand::FORCE_RED    => 0,
            TrafficLightCommand::SET_PHASE    => (int) ($command->payload['phase'] ?? 0),
            TrafficLightCommand::CLEAR_FAULT  => 0x0001,
            default                            => 0,
        };

        $socket = $this->open($host, $port, $timeout);
        try {
            $tx = random_int(1, 0xFFFF);
            $request = $this->frameWriteSingleRegister($tx, $unitId, $address, $value);
            fwrite($socket, $request);
            $response = $this->readResponse($socket);
            $this->parseWriteResponse($response, $tx);
        } finally {
            fclose($socket);
        }

        return true;
    }

    // ── Sockets / framing ────────────────────────────────────────────────

    /** @return resource */
    private function open(string $host, int $port, float $timeout)
    {
        $errno = 0; $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout,
        );
        if ($socket === false) {
            throw new TrafficLightException(sprintf('Falha ao conectar em %s:%d — %s', $host, $port, $errstr));
        }
        stream_set_timeout($socket, (int) $timeout);
        return $socket;
    }

    /** @return array{0:string,1:int} */
    private function parseEndpoint(string $endpoint): array
    {
        if (!str_contains($endpoint, ':')) {
            return [$endpoint, 502];
        }
        [$host, $port] = explode(':', $endpoint, 2);
        return [$host, (int) $port];
    }

    private function frameReadHoldingRegisters(int $tx, int $unitId, int $address, int $quantity): string
    {
        $pdu     = chr(0x03) . pack('nn', $address, $quantity);
        $mbap    = pack('nnn', $tx, 0, strlen($pdu) + 1) . chr($unitId);
        return $mbap . $pdu;
    }

    private function frameWriteSingleRegister(int $tx, int $unitId, int $address, int $value): string
    {
        $pdu  = chr(0x06) . pack('nn', $address, $value);
        $mbap = pack('nnn', $tx, 0, strlen($pdu) + 1) . chr($unitId);
        return $mbap . $pdu;
    }

    private function readResponse($socket): string
    {
        $header = $this->readExactly($socket, 6);
        $length = unpack('n', substr($header, 4, 2))[1];
        $body   = $this->readExactly($socket, $length);
        return $header . $body;
    }

    private function readExactly($socket, int $bytes): string
    {
        $buffer = '';
        while (strlen($buffer) < $bytes) {
            $chunk = fread($socket, $bytes - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new TrafficLightException('Timeout lendo resposta Modbus TCP.');
                }
                throw new TrafficLightException('Conexão Modbus TCP encerrada inesperadamente.');
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    /** @return int[] */
    private function parseReadResponse(string $response, int $expectedTx): array
    {
        $tx = unpack('n', substr($response, 0, 2))[1];
        if ($tx !== $expectedTx) {
            throw new TrafficLightException('Transaction ID Modbus não corresponde.');
        }

        $pdu = substr($response, 7);
        $fc  = ord($pdu[0]);
        if ($fc & 0x80) {
            $code = ord($pdu[1] ?? "\x00");
            throw new TrafficLightException(sprintf('Modbus exception 0x%02X.', $code));
        }
        if ($fc !== 0x03) {
            throw new TrafficLightException(sprintf('Função Modbus inesperada 0x%02X.', $fc));
        }

        $byteCount = ord($pdu[1]);
        $data      = substr($pdu, 2, $byteCount);

        $registers = [];
        for ($i = 0; $i < $byteCount; $i += 2) {
            $registers[] = unpack('n', substr($data, $i, 2))[1];
        }
        return $registers;
    }

    private function parseWriteResponse(string $response, int $expectedTx): void
    {
        $tx = unpack('n', substr($response, 0, 2))[1];
        if ($tx !== $expectedTx) {
            throw new TrafficLightException('Transaction ID Modbus não corresponde (write).');
        }
        $fc = ord(substr($response, 7, 1));
        if ($fc & 0x80) {
            throw new TrafficLightException(sprintf('Modbus exception 0x%02X (write).', ord($response[8])));
        }
    }

    /** @param int[] $registers */
    private function buildPhases(array $registers): array
    {
        // Convenção simples: os primeiros 8 registradores são fases 1..8 (0=RED, 1=GREEN, 2=YELLOW, 3=OFF).
        $phases = [];
        for ($i = 0; $i < 8 && $i < count($registers); $i++) {
            $code = $registers[$i];
            $color = match ($code) {
                0 => SignalPhase::COLOR_RED,
                1 => SignalPhase::COLOR_GREEN,
                2 => SignalPhase::COLOR_YELLOW,
                default => SignalPhase::COLOR_OFF,
            };
            $phases[] = new SignalPhase(number: $i + 1, color: $color);
        }
        return $phases;
    }

    private function mapModeToRegister(string $mode): int
    {
        return match (strtoupper($mode)) {
            'AUTOMATIC' => 1,
            'MANUAL'    => 2,
            'FLASH'     => 6,
            default     => 1,
        };
    }
}
