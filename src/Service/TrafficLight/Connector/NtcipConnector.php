<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Connector;

use App\Dto\TrafficLight\SignalPhase;
use App\Dto\TrafficLight\TrafficLightCommand;
use App\Dto\TrafficLight\TrafficLightFault;
use App\Dto\TrafficLight\TrafficLightState;
use App\Service\TrafficLight\Exception\TrafficLightException;
use App\Service\TrafficLight\Profile\VendorProfileRegistry;
use App\Service\TrafficLight\TrafficLightConnectorInterface;

final class NtcipConnector implements TrafficLightConnectorInterface
{
    private const PROTOCOL = 'NTCIP';

    private const DEFAULT_OIDS = [
        'unitControlStatus'          => '1.3.6.1.4.1.1206.4.1.1.1.2.1.4.0',
        'unitFlashStatus'            => '1.3.6.1.4.1.1206.4.1.1.1.2.1.3.0',
        'unitAlarmStatus2'           => '1.3.6.1.4.1.1206.4.1.1.1.2.1.2.0',
        'phaseStatusGroupReds'       => '1.3.6.1.4.1.1206.4.1.1.1.2.1.5.0',
        'phaseStatusGroupYellows'    => '1.3.6.1.4.1.1206.4.1.1.1.2.1.6.0',
        'phaseStatusGroupGreens'     => '1.3.6.1.4.1.1206.4.1.1.1.2.1.7.0',
        'phaseStatusGroupPedClears'  => '1.3.6.1.4.1.1206.4.1.1.1.2.1.9.0',
        'unitControl'                => '1.3.6.1.4.1.1206.4.1.1.1.2.2.1.0',
        'phaseControlGroupPhaseOmit' => '1.3.6.1.4.1.1206.4.1.1.1.2.3.1.0',
    ];

    public function __construct(
        private readonly VendorProfileRegistry $profiles,
    ) {}

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

        $vendor  = isset($options['vendor']) ? (string) $options['vendor'] : null;
        $profile = $this->profiles->get('NTCIP', $vendor);
        $oids    = array_replace(
            $profile?->getOids() ?? self::DEFAULT_OIDS,
            (array) ($options['oids'] ?? []),
        );

        $peer = sprintf('%s:%d', $host, $port);

        $raw = [];
        $values = [];
        foreach ($oids as $name => $oid) {
            $value = @snmp2_get($peer, $community, $oid, $timeoutUs, $retries);
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
            controllerId: $peer,
            protocol:     self::PROTOCOL,
            readAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            currentPhase: $this->guessCurrentPhase($phases),
            cycleSeconds: null,
            mode:         $mode,
            phases:       $phases,
            detectors:    [],
            faults:       $faults,
            raw:          $raw,
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

        $vendor  = isset($options['vendor']) ? (string) $options['vendor'] : null;
        $profile = $this->profiles->get('NTCIP', $vendor);
        $oids    = array_replace(
            $profile?->getOids() ?? self::DEFAULT_OIDS,
            (array) ($options['oids'] ?? []),
        );

        $peer = sprintf('%s:%d', $host, $port);

        $apply = function (string $oid, string $type, mixed $value) use ($peer, $community, $timeoutUs, $retries): bool {
            return @snmp2_set($peer, $community, $oid, $type, $value, $timeoutUs, $retries) !== false;
        };

        return match ($command->type) {
            // ── Modo ──────────────────────────────────────────────────────
            TrafficLightCommand::SET_MODE,
            TrafficLightCommand::FLASH_YELLOW,
            TrafficLightCommand::ALL_RED,
            TrafficLightCommand::ALL_DARK => $apply(
                $oids['unitControl'],
                'i',
                $this->mapCommandToUnitControl($command),
            ),

            // ── Fases ─────────────────────────────────────────────────────
            TrafficLightCommand::SET_PHASE,
            TrafficLightCommand::FORCE_GREEN,
            TrafficLightCommand::FORCE_RED => $this->writePhaseControl($apply, $oids, $command),

            // ── Tempos (usam OIDs do MIB 1202 de coordenação) ─────────────
            TrafficLightCommand::SET_CYCLE  => $apply(
                $oids['cycleTime'] ?? '1.3.6.1.4.1.1206.4.1.1.4.2.1.2.0',
                'i',
                (int) ($command->payload['seconds'] ?? 90),
            ),

            TrafficLightCommand::SET_SPLIT  => $this->writeSplit($apply, $oids, $command),
            TrafficLightCommand::SET_OFFSET => $apply(
                $oids['offsetTime'] ?? '1.3.6.1.4.1.1206.4.1.1.4.2.1.4.0',
                'i',
                (int) ($command->payload['seconds'] ?? 0),
            ),

            // ── Limpeza de falhas ─────────────────────────────────────────
            TrafficLightCommand::CLEAR_FAULT => $apply($oids['unitAlarmStatus2'], 's', "\x00"),

            default => throw new TrafficLightException("Comando não suportado por NTCIP: {$command->type}"),
        };
    }

    private function mapCommandToUnitControl(TrafficLightCommand $command): int
    {
        return match ($command->type) {
            TrafficLightCommand::FLASH_YELLOW => 6,   // flashControl (piscante)
            TrafficLightCommand::ALL_RED      => 4,   // manualControl — controlador aplica all-red
            TrafficLightCommand::ALL_DARK     => 7,   // darkControl (apagado)
            TrafficLightCommand::SET_MODE     => $this->mapModeToUnitControl((string) ($command->payload['mode'] ?? 'AUTOMATIC')),
            default                           => 2,
        };
    }

    /** @param callable(string,string,mixed):bool $apply */
    private function writeSplit(callable $apply, array $oids, TrafficLightCommand $command): bool
    {
        $phase  = (int) ($command->payload['phase'] ?? 1);
        $green  = (int) ($command->payload['green'] ?? 30);
        $yellow = (int) ($command->payload['yellow'] ?? 3);
        $red    = (int) ($command->payload['red'] ?? 0);

        if ($phase < 1 || $phase > 8) {
            throw new TrafficLightException('Fase inválida (1–8).');
        }

        $oidGreen  = $oids['splitGreen']  ?? sprintf('1.3.6.1.4.1.1206.4.1.1.4.3.1.%d.1.2.%d', $phase, $phase);
        $oidYellow = $oids['splitYellow'] ?? sprintf('1.3.6.1.4.1.1206.4.1.1.4.3.1.%d.1.3.%d', $phase, $phase);
        $oidRed    = $oids['splitRed']    ?? sprintf('1.3.6.1.4.1.1206.4.1.1.4.3.1.%d.1.4.%d', $phase, $phase);

        // Alguns fabricantes aceitam os splits em décimos de segundo.
        $mul = (int) ($oids['splitUnit'] ?? 1);

        return $apply($oidGreen, 'i', $green * $mul)
            && $apply($oidYellow, 'i', $yellow * $mul)
            && $apply($oidRed, 'i', $red * $mul);
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

    private function snmpHexToBinary(string $value): string
    {
        if (preg_match('/^([0-9A-Fa-f]{2}\s?)+$/', trim($value))) {
            return hex2bin(str_replace(' ', '', trim($value))) ?: '';
        }
        return $value;
    }

    /** @return array<int, bool> */
    private function octetStringToBitArray(string $value): array
    {
        $bin  = $this->snmpHexToBinary($value);
        $bits = [];
        $len  = strlen($bin);
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
        $count  = max(count($reds), count($yellows), count($greens));
        $phases = [];
        for ($n = 1; $n <= $count; $n++) {
            $color = SignalPhase::COLOR_OFF;
            if ($greens[$n]  ?? false) $color = SignalPhase::COLOR_GREEN;
            elseif ($yellows[$n] ?? false) $color = SignalPhase::COLOR_YELLOW;
            elseif ($reds[$n]    ?? false) $color = SignalPhase::COLOR_RED;

            $phases[] = new SignalPhase(
                number:     $n,
                color:      $color,
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
            'SYSTEM', 'AUTOMATIC' => 2,
            'LOCAL'               => 3,
            'MANUAL'              => 4,
            default               => 2,
        };
    }

    /** @param callable(string,string,mixed):bool $apply */
    private function writePhaseControl(callable $apply, array $oids, TrafficLightCommand $command): bool
    {
        $ok = $apply($oids['unitControl'], 'i', 4);
        if (!$ok) {
            return false;
        }

        $phaseNumber = (int) ($command->payload['phase'] ?? 0);
        if ($phaseNumber < 1 || $phaseNumber > 8) {
            throw new TrafficLightException('Número de fase fora do intervalo 1–8.');
        }

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
