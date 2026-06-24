<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CemadenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cemaden:collect',
    description: 'Coleta dados hidrológicos e de chuva do CEMADEN (substitui dadoscemadem.php e hidrologicocemadem*.php)',
)]
class CollectCemadenCommand extends Command
{
    public function __construct(
        private readonly CemadenService $cemadenService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('state', InputArgument::OPTIONAL, 'UF do estado (ex: MG, SP, RJ)', 'MG');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $state = strtoupper((string) $input->getArgument('state'));

        $io->title("Coleta CEMADEN — Estado: {$state}");

        try {
            $result = $this->cemadenService->collectData($state);
            $io->success(sprintf('Recebidos %d registros da API, %d salvos.', $result['total'], $result['saved']));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erro na coleta CEMADEN: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
