<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'waze:report:daily',
    description: 'Envia o relatório diário por e-mail para todos os usuários ativos (substitui send_daily_report.php)',
)]
class SendDailyReportCommand extends Command
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('date', 'd', InputOption::VALUE_OPTIONAL, 'Data do relatório (Y-m-d). Padrão: ontem.', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dateStr = $input->getOption('date');
        $date    = $dateStr
            ? new \DateTimeImmutable($dateStr)
            : new \DateTimeImmutable('yesterday');

        $io->title('Relatório Diário — ' . $date->format('d/m/Y'));

        try {
            $this->notificationService->sendDailyReport($date);
            $io->success('Relatórios enviados com sucesso.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erro ao enviar relatório: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
