<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\TrafficLight;
use App\Repository\PartnerRepository;
use App\Repository\TrafficLightRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:traffic-light:import-sensorthings',
    description: 'Descobre controladores SensorThings e importa para o banco.',
)]
final class ImportSensorThingsCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PartnerRepository $partnerRepository,
        private readonly TrafficLightRepository $lightRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', 'p', InputOption::VALUE_REQUIRED, 'Código do parceiro (ex.: laf)')
            ->addOption('base',    null, InputOption::VALUE_REQUIRED, 'Base URL SensorThings', 'https://tld.iot.hamburg.de/v1.1')
            ->addOption('service', null, InputOption::VALUE_REQUIRED, 'serviceName', 'HH_STA_traffic_lights')
            ->addOption('layer',   null, InputOption::VALUE_REQUIRED, 'layerName', 'primary_signal')
            ->addOption('limit',   null, InputOption::VALUE_REQUIRED, 'Máximo de itens', '10')
            ->addOption('prefix',  null, InputOption::VALUE_REQUIRED, 'Prefixo do código', 'HH')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Só mostra o que faria, sem salvar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $partnerCode = (string) $input->getOption('partner');
        if ($partnerCode === '') {
            $io->error('Informe --partner=CODIGO (ex.: --partner=laf)');
            return Command::INVALID;
        }

        $partner = $this->partnerRepository->findOneBy(['code' => $partnerCode, 'isActive' => true]);
        if ($partner === null) {
            $io->error(sprintf("Parceiro '%s' não encontrado ou inativo.", $partnerCode));
            return Command::FAILURE;
        }

        $base      = rtrim((string) $input->getOption('base'), '/');
        $service   = (string) $input->getOption('service');
        $layerName = (string) $input->getOption('layer');
        $limit     = max(1, (int) $input->getOption('limit'));
        $prefix    = strtoupper((string) $input->getOption('prefix'));
        $dryRun    = (bool) $input->getOption('dry-run');

        $filter = sprintf(
            "properties/serviceName eq '%s' and properties/layerName eq '%s'",
            $service,
            $layerName,
        );

        $url = $base . '/Datastreams'
             . '?$filter=' . rawurlencode($filter)
             . '&$top='   . $limit
             . '&$expand=Thing($expand=Locations)'
             . '&$orderby=@iot.id%20asc';

        $io->section('Consultando a API SensorThings');
        $io->writeln("  URL: <info>{$url}</info>");

        try {
            $res  = $this->httpClient->request('GET', $url, ['timeout' => 20]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $io->error('Falha na consulta: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $items = $data['value'] ?? [];
        if ($items === []) {
            $io->warning('Nenhum controlador encontrado com esse filtro.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('  <info>%d</info> controlador(es) encontrado(s).', count($items)));

        $created = 0;
        $skipped = 0;

        foreach ($items as $ds) {
            $thing   = $ds['Thing'] ?? [];
            $coords  = $this->extractCentroid(
                $thing['Locations'][0]['location']['geometry']['coordinates'] ?? null,
            );

            $thingName  = (string) ($thing['name'] ?? 'unknown');
            $signalGrp  = (string) ($ds['properties']['signalGroupID'] ?? '');
            $connection = (string) ($thing['properties']['connectionID'] ?? '');

            // Ex.: HH-353-13-K5  (prefixo + thingName normalizado + signal group)
            $code = $this->buildCode($prefix, $thingName, $signalGrp, (int) ($ds['@iot.id'] ?? 0));

            $existing = $this->lightRepository->findOneByPartnerAndCode($partner, $code);
            if ($existing !== null) {
                $io->writeln(sprintf('  <comment>↷</comment> %-22s já existe (id=%d)', $code, $existing->getId()));
                $skipped++;
                continue;
            }

            $light = (new TrafficLight())
                ->setPartner($partner)
                ->setCode($code)
                ->setName(sprintf('Hamburgo · %s%s', $thingName, $signalGrp !== '' ? ' · ' . $signalGrp : ''))
                ->setProtocol(TrafficLight::PROTOCOL_SENSORTHINGS)
                ->setEndpoint((string) ($ds['@iot.selfLink'] ?? ''))
                ->setOptions(['timeout' => 10])
                ->setLatitude($coords['lat'])
                ->setLongitude($coords['lng'])
                ->setIsActive(true);

            if ($dryRun) {
                $io->writeln(sprintf('  <info>·</info> %-22s (dry-run) %s', $code, $light->getEndpoint()));
            } else {
                $this->em->persist($light);
                $io->writeln(sprintf('  <info>+</info> %-22s %s', $code, $light->getEndpoint()));
            }
            $created++;
        }

        if (!$dryRun && $created > 0) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%s: %d criado(s), %d ignorado(s) (já existiam).',
            $dryRun ? 'Dry-run' : 'Importação concluída',
            $created,
            $skipped,
        ));

        if ($dryRun) {
            $io->note('Nada foi salvo. Rode sem --dry-run para persistir.');
        } else {
            $io->note('Rode "php bin/console app:traffic-light:poll" para gerar o primeiro snapshot de cada um.');
        }

        return Command::SUCCESS;
    }

    private function buildCode(string $prefix, string $thingName, string $signalGroup, int $datastreamId): string
    {
        $slug = preg_replace('/[^A-Z0-9\-_]+/i', '-', strtoupper($thingName)) ?? $thingName;
        $slug = trim((string) $slug, '-');

        $parts = array_filter([$prefix, $slug, $signalGroup]);
        $base  = implode('-', $parts);

        // Garante unicidade se o mesmo thingName/signalGroup vier em vários Datastreams
        return mb_substr($base . '-' . $datastreamId, 0, 100);
    }

    /** @return array{lat:?float,lng:?float} */
    private function extractCentroid(mixed $coordinates): array
    {
        $points = [];

        $walk = function (array $node) use (&$walk, &$points): void {
            if (isset($node[0], $node[1]) && is_numeric($node[0]) && is_numeric($node[1])) {
                $points[] = [(float) $node[0], (float) $node[1]];
                return;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };

        if (is_array($coordinates)) {
            $walk($coordinates);
        }

        if ($points === []) {
            return ['lat' => null, 'lng' => null];
        }

        $sumLng = 0.0;
        $sumLat = 0.0;
        foreach ($points as [$lng, $lat]) {
            $sumLng += $lng;
            $sumLat += $lat;
        }
        $n = count($points);

        return [
            'lat' => round($sumLat / $n, 7),
            'lng' => round($sumLng / $n, 7),
        ];
    }
}
