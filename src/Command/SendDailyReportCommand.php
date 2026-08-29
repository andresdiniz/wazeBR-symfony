<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Repository\PartnerRepository;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'waze:report:daily',
    description: 'Envia o relatório diário por e-mail para todos os usuários ativos',
)]
class SendDailyReportCommand extends Command
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly PartnerRepository $partnerRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'date',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Data do relatório (Y-m-d). Padrão: ontem.',
            null,
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        try {
            $dateStr = $input->getOption('date');

            if ($dateStr !== null && $dateStr !== '') {
                $date = new \DateTimeImmutable($dateStr, new \DateTimeZone('UTC'));
            } else {
                $date = new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC'));
            }

            $io->title('Relatório Diário — ' . $date->format('d/m/Y'));

            $partners = $this->partnerRepository->findAll();
            $processed = 0;

            foreach ($partners as $partner) {
                if (!$partner instanceof Partner) {
                    continue;
                }

                $this->notificationService->sendDailyReportForPartner($partner, $date);
                $processed++;
            }

            if ($processed === 0) {
                $io->warning('Nenhum parceiro encontrado para gerar o relatório.');
            } else {
                $io->success(sprintf(
                    'Relatórios enviados com sucesso para %d parceiro(s).',
                    $processed,
                ));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erro ao enviar relatório: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
