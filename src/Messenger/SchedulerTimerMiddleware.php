<?php

declare(strict_types=1);

namespace App\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Middleware que envolve cada RunCommandMessage executado pelo worker
 * do Messenger (o mesmo que o scheduler_default dispara).
 *
 * Grava no canal "scheduler" do Monolog:
 *   ▶ Iniciando <comando>
 *   ✓ <comando> concluído em X ms
 *   ✗ <comando> falhou em X ms: <erro>
 */
final class SchedulerTimerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $schedulerLogger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof RunCommandMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Extrai o nome do comando (primeiro token).
        // Ex.: "app:fetch-waze-feed --no-interaction" → "app:fetch-waze-feed"
        $input       = trim($message->input ?? '');
        $commandName = strtok($input, ' ') ?: $input;

        $stopwatch = new Stopwatch();
        $stopwatch->start('scheduler.cmd');

        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->schedulerLogger->info('▶ Iniciando {command}', [
            'command'    => $commandName,
            'full_input' => $input,
            'started_at' => $startedAt->format(DATE_ATOM),
        ]);

        try {
            $envelope = $stack->next()->handle($envelope, $stack);

            $event    = $stopwatch->stop('scheduler.cmd');
            $duration = $event->getDuration(); // ms
            $memory   = $event->getMemory();   // bytes

            $this->schedulerLogger->info('✓ {command} concluído em {duration_ms} ms ({duration_s} s, memória pico {peak_mb} MB)', [
                'command'     => $commandName,
                'duration_ms' => $duration,
                'duration_s'  => round($duration / 1000, 2),
                'peak_mb'     => round($memory / 1024 / 1024, 1),
                'finished_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ]);

            return $envelope;
        } catch (\Throwable $e) {
            $event = $stopwatch->stop('scheduler.cmd');

            $this->schedulerLogger->error('✗ {command} falhou em {duration_ms} ms: {error}', [
                'command'     => $commandName,
                'duration_ms' => $event->getDuration(),
                'error'       => $e->getMessage(),
                'exception'   => $e,
                'failed_at'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ]);

            throw $e;
        }
    }
}
