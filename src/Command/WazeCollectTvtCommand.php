<?php

namespace App\Command;

use App\Entity\WazeTvtRouteDefinition;
use App\Entity\WazeTvtRouteExecution;
use App\Entity\WazeTvtRouteExecutionCoord;
use App\Repository\WazeTvtRouteDefinitionRepository;
use App\Repository\WazeTvtRouteExecutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Log\LoggerInterface;

#[AsCommand(
    name: 'waze:collect-tvt',
    description: 'Collect TVT (travel time) data from Waze API and store using new Definition/Execution structure',
)]
class WazeCollectTvtCommand extends Command
{
    private const API_BASE = 'https://api.waze.com/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private WazeTvtRouteDefinitionRepository $definitionRepo,
        private WazeTvtRouteExecutionRepository $executionRepo,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Waze TVT Collection');

        $routes = $this->getMonitoredRoutes();

        if (empty($routes)) {
            $io->warning('No monitored routes found.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Collecting data for %d route(s)', count($routes)));

        foreach ($routes as $route) {
            try {
                $this->collectRoute($route, $io);
            } catch (\Throwable $e) {
                $this->logger->error('Error collecting route: ' . $e->getMessage(), [
                    'route' => $route,
                    'exception' => $e,
                ]);
                $io->warning(sprintf('Failed to collect route %s: %s', $route['id'] ?? 'unknown', $e->getMessage()));
            }
        }

        $io->success('TVT collection completed.');
        return Command::SUCCESS;
    }

    private function getMonitoredRoutes(): array
    {
        $conn = $this->em->getConnection();
        return $conn->fetchAllAssociative('SELECT id, route_id, name FROM waze_routes WHERE active = 1');
    }

    private function collectRoute(array $route, SymfonyStyle $io): void
    {
        $routeId = $route['route_id'];
        $io->text(sprintf('→ Route %s (%s)', $routeId, $route['name'] ?? 'unnamed'));

        $data = $this->fetchTvtData($routeId);
        if (!$data) {
            return;
        }

        $definition = $this->definitionRepo->findOneByRouteId($routeId);
        if (!$definition) {
            $definition = new WazeTvtRouteDefinition();
            $definition->setRouteId($routeId);
            $definition->setName($data['name'] ?? $route['name'] ?? null);
            $definition->setBbox($this->encodeJson($data['bbox'] ?? null));
            $definition->setLine($this->encodeJson($data['line'] ?? null));
            $this->em->persist($definition);
            $this->em->flush();
            $io->text(sprintf('  Created definition for %s', $routeId));
        }

        $execution = new WazeTvtRouteExecution();
        $execution->setRouteDefinition($definition);
        $execution->setTimestamp(new \DateTimeImmutable());
        $execution->setDuration($data['duration'] ?? null);
        $execution->setLength($data['length'] ?? null);
        $execution->setIrregularities($data['irregularities'] ?? 0);
        $execution->setTrafficJams($data['trafficJams'] ?? 0);
        $execution->setAvgSpeed($data['avgSpeed'] ?? null);
        $execution->setCoords($this->encodeJson($data['coords'] ?? null));

        $this->em->persist($execution);
        $this->em->flush();

        if (!empty($data['coordsDetailed'])) {
            foreach ($data['coordsDetailed'] as $i => $coord) {
                $coordEntity = new WazeTvtRouteExecutionCoord();
                $coordEntity->setExecution($execution);
                $coordEntity->setPosition($i);
                $coordEntity->setLat((float) ($coord['lat'] ?? 0));
                $coordEntity->setLng((float) ($coord['lng'] ?? 0));
                $coordEntity->setSpeed($coord['speed'] ?? null);
                $coordEntity->setLevel($coord['level'] ?? null);
                $this->em->persist($coordEntity);
            }
            $this->em->flush();
        }

        $io->text(sprintf('  Saved execution: duration=%s, length=%s, jams=%d', 
            $data['duration'] ?? 'null', 
            $data['length'] ?? 'null', 
            $data['trafficJams'] ?? 0
        ));
    }

    private function fetchTvtData(string $routeId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE . 'tvt/' . urlencode($routeId), [
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('TVT API returned non-200', ['status' => $response->getStatusCode(), 'route' => $routeId]);
                return null;
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch TVT data', ['route' => $routeId, 'exception' => $e]);
            return null;
        }
    }

    private function encodeJson(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}
