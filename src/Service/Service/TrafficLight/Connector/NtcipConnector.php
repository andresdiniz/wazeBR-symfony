<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Connector;

use App\Dto\TrafficLight\SignalPhase;
use App\Dto\TrafficLight\TrafficLightCommand;
use App\Dto\TrafficLight\TrafficLightFault;
use App\Dto\TrafficLight\TrafficLightState;
use App\Service\TrafficLight\Exception\TrafficLightException;
use App\Service\TrafficLight\TrafficLightConnectorInterface;

final class NtcipConnector implements TrafficLightConnectorInterface
{
    private const PROTOCOL = 'NTCIP';

    // OIDs NTCIP 1202 (trecho relevante — podem ser sobrescritos por $options['oids'])
    private const DEFAULT_OIDS = [
        'unitControlStatus'         => '1.3.6.1.4.1.1206.4.1.1.1.2.1.4.0',
        'unitFlashStatus'           => '1.3.6.1.4.1.1206.4.1.1.1.2.1.3.0',
        'unitAlarmStatus2'          => '1.3.6.1.4.1.1206.4.1.1.1.2.1.2.0',
        'phaseStatusGroupReds'      => '1.3.6.1.4.1.1206.4.1.1.1.2.1.5.0',
        'phaseStatusGroupYellows'   => '1.3.6.1.4.1.1206.4.1.1.1.2.1.6.0',
        'phaseStatusGroupGreens'    => '1.3.6.1.4.1.1206.4.1.1.1.2.1.7.0',
        'phaseStatusGroupPedClears' => '1.3.6.1.4.1.1206.4.1.1.1.2.1.9.0',
        'unitControl'               => '1.3.6.1.4.1.1206.4.1.1.1.2.2.1.0',
        'phaseControlGroupPhaseOmit'=> '1.3.6.1.4.1.1206.4.1.1.1.2.3.1.0',
    ];

    public function supports(string $protocol): bool
    {
        return strtoupper($protocol) === self::PROTOCOL;
    }

    public function read(string $endpoint, array $options = []): TrafficLightState
    {
        if (!function_exists('snmp2_get')) {
            throw new TrafficLightException('Extensão PHP "snmp" não instalada.');
        }

        [$host, $port] = $this->parseEndpoint($endpoint);

        $community = (string) ($options['community'] ?? 'public');
        $timeoutUs = (int) (($options['timeout'] ?? 2) * 1_000_000);
        $retries   = (int) ($options['retries'] ?? 1);
        $oids      = array_replace(self::DEFAULT_OIDS, (array) ($options['oids'] ?? []));
        $peer      = sprintf('%s:%d', $host, $port);

        $read = fn (string $oid): string|false => @snmp2_get($peer, $community, $oid, $timeoutUs, $retries);

        $raw = [];
        $values = [];
        foreach ($oids as $name => $oid) {
            $value = $read($oid);
            if ($value === false) {
                continue;
            }
            $values[$name] = $value;
            $raw[$name]    = $value;
        }

        if ($values === []) {
            throw new TrafficLightException(sprintf('Nenhum OID respondeu em %s.', $peer));
        }

        $phases = $this->buildPhases(
            $this->octetStringToBitArray((string) ($values['phaseStatusGroupReds'] ?? '')),
            $this->octetStringToBitArray((string) ($values['phaseStatusGroupYellows'] ?? '')),
            $this->octetStringToBitArray((string) ($values['phaseStatusGroupGreens'] ?? '')),
            $this->octetStringToBitArray((string) ($values['phaseStatusGroupPedClears'] ?? '')),
        );

        $faults = $this->parseFaults((string) ($values['unitAlarmStatus2'] ?? ''));

        $mode = TrafficLightState::MODE_AUTOMATIC;
        if (($values['unitFlashStatus'] ?? '') !== '' && $this->isFlashing((string) $values['unitFlashStatus'])) {
            $mode = TrafficLightState::MODE_FLASH;
        }

        return new TrafficLightState(
            controllerId:   $peer,
            protocol:       self::PROTOCOL,
            readAt:         new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            currentPhase:   $this->guessCurrentPhase($phases),
            cycleSeconds:   null,
            mode:           $mode,
            phases:         $phases,
            detectors:      [],
            faults:         $faults,
            raw:            $raw,
        );
    }

    public function write(
        string $endpoint,
        TrafficLightCommand $command,
        array $options = [],
    ): bool {
        if (!function_exists('snmp2_set')) {
            throw new TrafficLightException('Extensão PHP "snmp" não instalada.');
        }

        [$host, $port] = $this->parseEndpoint($endpoint);

        $community = (string) ($options['community'] ?? 'private');
        $timeoutUs = (int) (($options['timeout'] ?? 2) * 1_000_000);
        $retries   = (int) ($options['retries'] ?? 1);
        $oids      = array_replace(self::DEFAULT_OIDS, (array) ($options['oids'] ?? []));
        $peer      = sprintf('%s:%d', $host, $port);

        $apply = function (string $oid, string $type, mixed $value) use ($peer, $community, $timeoutUs, $retries): bool {
            return @snmp2_set($peer, $community, $oid, $type, $value, $timeoutUs, $retries) !== false;
        };

        return match ($command->type) {
            TrafficLightCommand::SET_MODE => $apply(
                $oids['unitControl'],
                'i',
                $this->mapModeToUnitControl((string) ($command->payload['mode'] ?? 'AUTOMATIC')),
            ),
            TrafficLightCommand::FLASH_YELLOW => $apply($oids['unitControl'], 'i', 6), // 6 = flash
            TrafficLightCommand::FORCE_GREEN, TrafficLightCommand::FORCE_RED, TrafficLightCommand::SET_PHASE
                => $this->writePhaseControl($apply, $oids, $command),
            TrafficLightCommand::CLEAR_FAULT
                => $apply($oids['unitAlarmStatus2'], 's', "\x00"),
            default => throw new TrafficLightException("Comando não suportado por NTCIP: {$command->type}"),
        };
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:string,1:int} */
    private function parseEndpoint(string $endpoint): array
    {
        if (str_contains($endpoint, ':')) {
            [$host, $port] = explode(':', $endpoint, 2);
            return [$host, (int) $port];
        }
        return [$endpoint, 161];
    }

    /** Converte OCTET STRING SNMP em string binária. */
    private function snmpHexToBinary(string $value): string
    {
        // snmp2_get retorna algo como "00 00 00 00" ou já binário dependendo da extensão.
        if (preg_match('/^([0-9A-Fa-f]{2}\s?)+$/', trim($value))) {
            return hex2bin(str_replace(' ', '', trim($value))) ?: '';
        }
        return $value;
    }

    /** @return array<int, bool> Phase number => active */
    private function octetStringToBitArray(string $value): array
    {
        $bin = $this->snmpHexToBinary($value);
        $bits = [];
        $len = strlen($bin);
        for ($byte = 0; $byte < $len; $byte++) {
            $octet = ord($bin[$byte]);
            for ($bit = 0; $bit < 8; $bit++) {
                $phase = $byte * 8 + $bit + 1;
                $bits[$phase] = (bool) ($octet & (1 << $bit));
            }
        }
        return $bits;
    }

    /**
     * @param array<int,bool> $reds
     * @param array<int,bool> $yellows
     * @param array<int,bool> $greens
     * @param array<int,bool> $pedClears
     * @return SignalPhase[]
     */
    private function buildPhases(array $reds, array $yellows, array $greens, array $pedClears): array
    {
        $count = max(count($reds), count($yellows), count($greens));
        $phases = [];
        for ($n = 1; $n <= $count; $n++) {
            $color = SignalPhase::COLOR_OFF;
            if ($greens[$n]  ?? false) $color = SignalPhase::COLOR_GREEN;
            elseif ($yellows[$n] ?? false) $color = SignalPhase::COLOR_YELLOW;
            elseif ($reds[$n]    ?? false) $color = SignalPhase::COLOR_RED;

            $phases[] = new SignalPhase(
                number: $n,
                color: $color,
                pedestrian: (bool) ($pedClears[$n] ?? false),
            );
        }
        return $phases;
    }

    private function guessCurrentPhase(array $phases): ?int
    {
        foreach ($phases as $p) {
            if ($p->isGreen()) {
                return $p->number;
            }
        }
        return null;
    }

    private function isFlashing(string $value): bool
    {
        $bin = $this->snmpHexToBinary($value);
        return $bin !== '' && ord($bin[0]) !== 0;
    }

    /** @return TrafficLightFault[] */
    private function parseFaults(string $value): array
    {
        $bin = $this->snmpHexToBinary($value);
        if ($bin === '') {
            return [];
        }
        $faults = [];
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $octet = ord($bin[$i]);
            if ($octet === 0) {
                continue;
            }
            $faults[] = new TrafficLightFault(
                code:       sprintf('NTCIP_ALARM_%d_%d', $i, $octet),
                severity:   TrafficLightFault::SEVERITY_MAJOR,
                message:    sprintf('Alarme NTCIP ativo no byte %d (mask 0x%02X).', $i, $octet),
                detectedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        }
        return $faults;
    }

    private function mapModeToUnitControl(string $mode): int
    {
        return match (strtoupper($mode)) {
            'SYSTEM', 'AUTOMATIC' => 2, // systemControl
            'LOCAL'               => 3, // localControl
            'MANUAL'              => 4, // manualControl
            default               => 2,
        };
    }

    /** @param callable(string,string,mixed):bool $apply */
    private function writePhaseControl(callable $apply, array $oids, TrafficLightCommand $command): bool
    {
        // Em NTCIP, mudar fase diretamente exige que o controlador esteja em modo "preempt" ou "manual".
        // Aqui apenas colocamos o controlador em manualControl e aplicamos a máscara de omissão de fase.
        // A lógica real depende do fabricante — ajuste conforme o MIB.
        $ok = $apply($oids['unitControl'], 'i', 4);
        if (!$ok) {
            return false;
        }

        $phaseNumber = (int) ($command->payload['phase'] ?? 0);
        if ($phaseNumber < 1 || $phaseNumber > 8) {
            throw new TrafficLightException('Número de fase fora do intervalo 1–8.');
        }

        // Omite todas as fases exceto a desejada (byte 0). Ajuste conforme seu controlador.
        $mask = "\xFF";
        $byte = 0;
        for ($i = 1; $i <= 8; $i++) {
            if ($i !== $phaseNumber) {
                $byte |= (1 << ($i - 1));
            }
        }
        $mask = chr($byte);

        return $apply($oids['phaseControlGroupPhaseOmit'], 's', $mask);
    }
}
