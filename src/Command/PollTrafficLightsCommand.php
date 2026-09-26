<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TrafficLightRepository;
use App\Service\TrafficLight\TrafficLightManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:traffic-light:poll',
    description: 'Lê todos os controladores de semáforo ativos e persiste snapshots.',
)]
final class PollTrafficLightsCommand extends Command
{
    public function __construct(
        private readonly TrafficLightRepository $repository,
        private readonly TrafficLightManager $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('code', null, InputOption::VALUE_REQUIRED, 'Filtra por código do controlador.');
        $this->addOption('id',   null, InputOption::VALUE_REQUIRED, 'Filtra por ID do controlador.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id   = $input->getOption('id');
        $code = $input->getOption('code');

        if ($id !== null) {
            $lights = array_filter([$this->repository->find((int) $id)]);
        } elseif ($code !== null) {
            $lights = $this->repository->findBy(['code' => $code, 'isActive' => true]);
        } else {
            $lights = $this->repository->findActive();
        }

        if ($lights === []) {
            $io->warning('Nenhum controlador ativo encontrado.');
            return Command::SUCCESS;
        }

        $ok = 0; $fail = 0;
        foreach ($lights as $light) {
            try {
                $snapshot = $this->manager->poll($light);
                if ($snapshot->isSuccess()) {
                    $ok++;
                    $io->writeln(sprintf(
                        '  <info>✓</info> %-15s fase=%s falhas=%d (%d ms)',
                        $light->getCode(),
                        $snapshot->getCurrentPhase() ?? '—',
                        $snapshot->getFaultCount(),
                        $snapshot->getDurationMs() ?? 0,
                    ));
                } else {
                    $fail++;
                    $io->writeln(sprintf(
                        '  <error>✗</error> %-15s %s',
                        $light->getCode(),
                        $snapshot->getErrorMessage(),
                    ));
                }
            } catch (\Throwable $e) {
                $fail++;
                $io->writeln(sprintf('  <error>✗</error> %-15s %s', $light->getCode(), $e->getMessage()));
            }
        }

        $io->newLine();
        $io->success(sprintf('Concluído: %d ok, %d falhas.', $ok, $fail));

        return $fail > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
