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

        $io->section('1. Creating unique route definitions from existing executions');

        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
            SELECT DISTINCT
                route_id,
                name,
                bbox,
                line
            FROM waze_tvt_routes
            WHERE route_id IS NOT NULL
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

        $io->section('2. Migrating executions linking to definitions');

        $execRows = $conn->fetchAllAssociative(
            <<<'SQL'
            SELECT
                id,
                route_id,
                timestamp,
                duration,
                length,
                irregularities,
                traffic_jams,
                avg_speed,
                coords,
                created_at
            FROM waze_tvt_routes
            WHERE route_id IS NOT NULL
            ORDER BY id
            SQL
        );

        $createdExec = 0;
        $skippedExec = 0;

        foreach ($execRows as $row) {
            $def = $this->em->getRepository(WazeTvtRouteDefinition::class)
                ->findOneBy(['routeId' => (string) $row['route_id']]);

            if (!$def) {
                $io->warning(sprintf('No definition for route_id "%s", skipping execution id=%d', $row['route_id'], $row['id']));
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

        $io->section('3. Migrating coords from waze_tvt_route_history_coords (if any)');

        $coordRows = $conn->fetchAllAssociative(
            <<<'SQL'
            SELECT
                id,
                execution_id,
                position,
                lat,
                lng,
                speed,
                level
            FROM waze_tvt_route_history_coords
            ORDER BY id
            SQL
        );

        $createdCoords = 0;

        foreach ($coordRows as $row) {
            $exec = $this->em->getRepository(WazeTvtRouteExecution::class)->find($row['execution_id']);
            if (!$exec) {
                $io->warning(sprintf('No execution id=%d for coord id=%d, skipping.', $row['execution_id'] ?? 0, $row['id']));
                continue;
            }

            $coord = new WazeTvtRouteExecutionCoord();
            $coord->setExecution($exec);
            $coord->setPosition((int) ($row['position'] ?? 0));
            $coord->setLat((float) ($row['lat'] ?? 0));
            $coord->setLng((float) ($row['lng'] ?? 0));
            $coord->setSpeed($row['speed'] !== null ? (float) $row['speed'] : null);
            $coord->setLevel($row['level'] !== null ? (int) $row['level'] : null);

            $this->em->persist($coord);
            $createdCoords++;
        }

        $this->em->flush();
        $io->success(sprintf('Created %d coord details.', $createdCoords));

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
