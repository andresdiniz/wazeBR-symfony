<?php

declare(strict_types=1);

namespace App\Messenger;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class PingConnectionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Só pinga em mensagens consumidas (não em dispatch)
        if ($envelope->last(ReceivedStamp::class) !== null) {
            $this->pingAndReconnect();
        }

        return $stack->next()->handle($envelope, $stack);
    }

    private function pingAndReconnect(): void
    {
        /** @var Connection $conn */
        $conn = $this->em->getConnection();

        try {
            // Ping simples: SELECT 1
            $conn->executeQuery('SELECT 1');
        } catch (\Throwable) {
            // Conexão morta → fecha e reabre
            $conn->close();

            if (!$this->em->isOpen()) {
                $this->em->clear();
            }

            $conn->connect();
        }
    }
}
