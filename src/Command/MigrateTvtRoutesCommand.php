<?php

namespace App\Command;

use App\Entity\WazeTvtRouteDefinition;
use App\Entity\WazeTvtRouteExecution;
use App\Entity\WazeTvtRouteExecutionCoord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'migrate:tvt-routes',
    description: 'Migrate existing waze_tvt_routes data to new Definition/Execution/Coord structure',
)]
class MigrateTvtRoutesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Migrating TVT routes to new schema');

        $conn = $this->em->getConnection();

        // Check if old table exists
        try {
            $conn->executeQuery('SELECT 1 FROM waze_tvt_routes LIMIT 1');
        } catch (\Throwable $e) {
            $io->warning('Table waze_tvt_routes does not exist or is not accessible. Skipping migration.');
            $io->text('New tables (waze_tvt_route_definition, waze_tvt_route_execution) should be created manually or via migration.');
            return Command::SUCCESS;
        }

        $io->section('1. Creating route definitions from existing executions');

        // Try to get distinct routes - adjust column names based on your actual schema
        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
            SELECT DISTINCT
                id as route_id,
                name,
                bbox,
                line
            FROM waze_tvt_routes
            WHERE id IS NOT NULL
            LIMIT 1000
            SQL
        );

        $createdDefs = 0;
        $skippedDefs = 0;

        foreach ($rows as $row) {
            $existing = $this->em->getRepository(WazeTvtRouteDefinition::class)
                ->findOneBy(['routeId' => (string) $row['route_id']]);

            if ($existing) {
                $skippedDefs++;
                continue;
            }

            $def = new WazeTvtRouteDefinition();
            $def->setRouteId((string) $row['route_id']);
            $def->setName($row['name'] ?? null);
            $def->setBbox($row['bbox'] ?? null);
            $def->setLine($row['line'] ?? null);

            $this->em->persist($def);
            $createdDefs++;
        }

        $this->em->flush();
        $io->success(sprintf('Created %d route definitions (skipped %d existing).', $createdDefs, $skippedDefs));

        $io->section('2. Creating executions linking to definitions');

        $execRows = $conn->fetchAllAssociative(
            <<<'SQL'
            SELECT
                id,
                timestamp,
                duration,
                length,
                irregularities,
                traffic_jams,
                avg_speed,
                coords,
                created_at
            FROM waze_tvt_routes
            ORDER BY id
            LIMIT 1000
            SQL
        );

        $createdExec = 0;
        $skippedExec = 0;

        foreach ($execRows as $row) {
            $def = $this->em->getRepository(WazeTvtRouteDefinition::class)
                ->findOneBy(['routeId' => (string) $row['id']]);

            if (!$def) {
                $io->warning(sprintf('No definition for route id=%d, skipping execution.', $row['id']));
                $skippedExec++;
                continue;
            }

            $exec = new WazeTvtRouteExecution();
            $exec->setRouteDefinition($def);
            $exec->setTimestamp($row['timestamp'] ? new \DateTimeImmutable($row['timestamp']) : null);
            $exec->setDuration($row['duration'] ?? null);
            $exec->setLength($row['length'] ?? null);
            $exec->setIrregularities((int) ($row['irregularities'] ?? 0));
            $exec->setTrafficJams((int) ($row['traffic_jams'] ?? 0));
            $exec->setAvgSpeed($row['avg_speed'] ?? null);
            $exec->setCoords($row['coords'] ?? null);
            $exec->setCreatedAt($row['created_at'] ? new \DateTimeImmutable($row['created_at']) : new \DateTimeImmutable());

            $this->em->persist($exec);
            $createdExec++;
        }

        $this->em->flush();
        $io->success(sprintf('Created %d executions (skipped %d).', $createdExec, $skippedExec));

        $io->newLine();
        $io->text('Migration completed. You can now:');
        $io->listing([
            'Update your collect command to use WazeTvtRouteDefinition for static data.',
            'Use WazeTvtRouteExecution for historical records.',
            'Optionally drop or repurpose old tables after validating the new data.',
        ]);

        return Command::SUCCESS;
    }
}
