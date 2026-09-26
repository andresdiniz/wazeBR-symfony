<?php

declare(strict_types=1);

namespace App\Service\TrafficLight;

use App\Dto\TrafficLight\TrafficLightCommand;
use App\Dto\TrafficLight\TrafficLightState;
use App\Entity\TrafficLight;
use App\Entity\TrafficLightSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class TrafficLightManager
{
    public function __construct(
        private readonly TrafficLightService $service,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Lê o controlador e persiste o snapshot. Nunca lança por falha de rede:
     * em erro, grava snapshot com success=false e marca o light como ERROR.
     */
    public function poll(TrafficLight $light): TrafficLightSnapshot
    {
        $start = microtime(true);
        $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $state = $this->service->read(
                protocol: (string) $light->getProtocol(),
                endpoint: (string) $light->getEndpoint(),
                options:  $light->getOptions(),
            );

            $snapshot = (new TrafficLightSnapshot())
                ->setTrafficLight($light)
                ->setReadAt($now)
                ->setSuccess(true)
                ->setState($state->toArray())
                ->setDurationMs((int) ((microtime(true) - $start) * 1000));

            $light->markReadOk($now);
        } catch (\Throwable $e) {
            $this->logger->warning('TrafficLight poll falhou', [
                'lightId' => $light->getId(),
                'code'    => $light->getCode(),
                'error'   => $e->getMessage(),
            ]);

            $snapshot = (new TrafficLightSnapshot())
                ->setTrafficLight($light)
                ->setReadAt($now)
                ->setSuccess(false)
                ->setErrorMessage($e->getMessage())
                ->setDurationMs((int) ((microtime(true) - $start) * 1000));

            $light->markReadError($now, $e->getMessage());
        }

        $this->em->persist($snapshot);
        $this->em->flush();

        return $snapshot;
    }

    /** Lê sem persistir. Lança em caso de falha. */
    public function readNow(TrafficLight $light): TrafficLightState
    {
        return $this->service->read(
            protocol: (string) $light->getProtocol(),
            endpoint: (string) $light->getEndpoint(),
            options:  $light->getOptions(),
        );
    }

    /** Escreve e persiste um snapshot de confirmação. */
    public function write(
        TrafficLight $light,
        TrafficLightCommand $command,
        ?int $operatorUserId = null,
    ): bool {
        $ok = $this->service->write(
            protocol: (string) $light->getProtocol(),
            endpoint: (string) $light->getEndpoint(),
            command:  $command,
            options:  $light->getOptions(),
        );

        // Registra a escrita como um snapshot sintético para auditoria.
        $snapshot = (new TrafficLightSnapshot())
            ->setTrafficLight($light)
            ->setReadAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setSuccess($ok)
            ->setState([
                'command'        => $command->type,
                'payload'        => $command->payload,
                'reason'         => $command->reason,
                'operatorUserId' => $operatorUserId,
            ]);

        if (!$ok) {
            $snapshot->setErrorMessage('Controlador não confirmou o comando.');
        }

        $this->em->persist($snapshot);
        $this->em->flush();

        return $ok;
    }
}
