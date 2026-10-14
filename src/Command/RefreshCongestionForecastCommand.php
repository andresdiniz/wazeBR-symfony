<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Service\CongestionForecastService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:tvt:refresh-congestion-forecast',
    description: 'Gera/atualiza a previsão de congestionamento das rotas TVT.',
)]
final class RefreshCongestionForecastCommand extends Command
{
    public function __construct(
        private readonly CongestionForecastService $forecastService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'ID do parceiro')
            ->addOption('slot', null, InputOption::VALUE_REQUIRED, 'Tamanho da janela em minutos', '30')
            ->addOption('slots-ahead', null, InputOption::VALUE_REQUIRED, 'Quantidade de janelas futuras', '2')
            ->addOption('history-days', null, InputOption::VALUE_REQUIRED, 'Dias de histórico', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $partnerId = $input->getOption('partner');
        $partner = null;

        if ($partnerId !== null) {
            $partner = $this->entityManager->find(Partner::class, (int) $partnerId);

            if ($partner === null) {
                $output->writeln(sprintf('<error>Parceiro %s não encontrado.</error>', $partnerId));

                return Command::FAILURE;
            }
        }

        $written = $this->forecastService->refreshUpcoming(
            $partner,
            max(5, (int) $input->getOption('slot')),
            max(1, (int) $input->getOption('slots-ahead')),
            max(7, (int) $input->getOption('history-days')),
        );

        $output->writeln(sprintf('<info>%d previsões atualizadas.</info>', $written));

        return Command::SUCCESS;
    }
}
