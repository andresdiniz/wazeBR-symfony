<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PartnerRepository;
use App\Service\PartnerFeedSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fetch-partner-feeds',
    description: 'Processa um partner vencido por vez.',
)]
final class FetchPartnerFeedsCommand extends Command
{
    public function __construct(
        private readonly PartnerRepository $partnerRepository,
        private readonly PartnerFeedSynchronizer $synchronizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'partner',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Processa somente este partner.',
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Executa sem persistir dados.',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Ignora a frequência para teste.',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $partnerOption = $input->getOption('partner');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $partner = $this->partnerRepository
            ->findNextDuePartner(
                $partnerOption !== null
                    ? (int) $partnerOption
                    : null,
                $force,
            );

        if ($partner === null) {
            $io->text(
                'Nenhum partner vencido para processamento.',
            );

            return Command::SUCCESS;
        }

        $io->title(sprintf(
            'Processando partner %d — %s',
            $partner->getId(),
            $partner->getName(),
        ));

        if ($dryRun) {
            $io->warning(
                'DRY-RUN: nenhuma alteração será persistida.',
            );
        }

        try {
            $result = $this->synchronizer
                ->synchronize($partner, $dryRun);

            $io->table(
                ['Métrica', 'Quantidade'],
                array_map(
                    static fn (
                        string $key,
                        int $value,
                    ): array => [$key, $value],
                    array_keys($result),
                    array_values($result),
                ),
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
