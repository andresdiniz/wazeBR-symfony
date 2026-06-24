<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'waze:notify:high-risk',
    description: 'Cria notificações para alertas de alto risco (substitui worker_notifications.php)',
)]
class NotifyHighRiskCommand extends Command
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Notificações de Alto Risco');

        try {
            $count = $this->notificationService->notifyHighRiskAlerts();
            $io->success("$count notificações criadas.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erro: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
