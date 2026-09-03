<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-old-snapshots',
    description: 'Remove snapshots antigos para otimizar o banco de dados',
)]
class PurgeOldSnapshotsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_OPTIONAL, 'Dias para manter (padr\u00e3o: 30)', 30)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'N\u00e3o remover, apenas mostrar o que seria removido');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');
        $dryRun = $input->getOption('dry-run');
        
        $io->title(sprintf('Purge de Snapshots Antigos (%d dias)', $days));
        
        $cutoffDate = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days));
        $io->text(sprintf('Data de corte: %s', $cutoffDate->format('Y-m-d H:i:s')));
        
        $conn = $this->em->getConnection();
        
        $tables = [
            'waze_route_snapshot_light' => 'recorded_at',
            'waze_tvt_route_history' => 'recorded_at',
            'waze_traffic_jam' => 'created_at',
        ];
        
        $totalRemoved = 0;
        
        foreach ($tables as $table => $dateColumn) {
            $io->section(sprintf('Tabela: %s', $table));
            
            $countSql = sprintf(
                'SELECT COUNT(*) FROM %s WHERE %s < :cutoff',
                $table,
                $dateColumn
            );
            
            $count = (int) $conn->fetchOne($countSql, ['cutoff' => $cutoffDate->format('Y-m-d H:i:s')]);
            
            if ($count === 0) {
                $io->text('Nenhum registro antigo encontrado.');
                continue;
            }
            
            $io->text(sprintf('Registros a remover: %d', $count));
            
            if ($dryRun) {
                $io->warning('Dry-run: nenhum registro foi removido.');
                continue;
            }
            
            $deleteSql = sprintf(
                'DELETE FROM %s WHERE %s < :cutoff',
                $table,
                $dateColumn
            );
            
            $affected = $conn->executeStatement($deleteSql, ['cutoff' => $cutoffDate->format('Y-m-d H:i:s')]);
            $totalRemoved += $affected;
            
            $io->success(sprintf('Removidos %d registros.', $affected));
        }
        
        $io->text(sprintf('Total removido: %d registros', $totalRemoved));
        
        if (!$dryRun && $totalRemoved > 0) {
            $io->note('Execute VACUUM ANALYZE no PostgreSQL para liberar espa\u00e7o.');
        }
        
        return Command::SUCCESS;
    }
}
