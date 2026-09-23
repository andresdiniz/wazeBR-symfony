<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Service\PartnerFeedSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fetch-partner-feeds',
    description: 'Busca e sincroniza os feeds dos partners configurados.'
)]
final class FetchPartnerFeedsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PartnerFeedSynchronizer $synchronizer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $totals = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($this->getPartners() as $partner) {
            $io->section(sprintf(
                'Processando partner %d — %s',
                $partner->getId(),
                $partner->getName()
            ));

            try {
                // O PartnerFeedSynchronizer já notifica a TV do partner
                // ao final do synchronize(), se houve escrita.
                $result = $this->synchronizer->synchronize($partner);
            } catch (\Throwable $exception) {
                $totals['errors']++;
                $io->error(sprintf(
                    'Falha no partner %d: %s',
                    $partner->getId(),
                    $exception->getMessage()
                ));
                continue;
            }

            $result = array_merge([
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
            ], is_array($result) ? $result : []);

            foreach ($totals as $key => $value) {
                $totals[$key] += (int) $result[$key];
            }

            $io->text(sprintf(
                'Resultado: %d processados, %d inseridos, %d atualizados, %d ignorados, %d erros.',
                $result['processed'],
                $result['inserted'],
                $result['updated'],
                $result['skipped'],
                $result['errors']
            ));
        }

        $io->section('Resumo final');
        $io->table(
            ['Métrica', 'Total'],
            [
                ['Processados', $totals['processed']],
                ['Inseridos', $totals['inserted']],
                ['Atualizados', $totals['updated']],
                ['Ignorados', $totals['skipped']],
                ['Erros', $totals['errors']],
            ]
        );

        $io->success(sprintf(
            'Processamento concluído: %d registro(s) inserido(s), %d atualizado(s), %d ignorado(s).',
            $totals['inserted'],
            $totals['updated'],
            $totals['skipped']
        ));

        return $totals['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<Partner> */
    private function getPartners(): array
    {
        return $this->entityManager
            ->getRepository(Partner::class)
            ->findBy([], [
                'lastFetchAt' => 'ASC',
                'id' => 'ASC',
            ]);
    }
}
