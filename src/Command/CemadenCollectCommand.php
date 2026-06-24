<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenData;
use App\Entity\Partner;
use App\Repository\CemadenDataRepository;
use App\Repository\PartnerRepository;
use App\Service\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:cemaden:collect',
    description: 'Coleta dados de chuva do CEMADEN para todos os parceiros ativos.',
)]
class CemadenCollectCommand extends Command
{
    private const CEMADEN_URL = 'http://sjc.salvar.cemaden.gov.br/resources/graficos/interativo/getJson2.php';

    public function __construct(
        private readonly PartnerRepository    $partnerRepository,
        private readonly CemadenDataRepository $cemadenRepo,
        private readonly TenantContext        $tenantContext,
        private readonly HttpClientInterface  $httpClient,
        private readonly LoggerInterface      $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', 'p', InputOption::VALUE_OPTIONAL, 'Slug do parceiro')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem persistir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $slug   = $input->getOption('partner');

        $io->title('WazeBR — Coleta CEMADEN');

        $partners = $slug
            ? array_filter($this->partnerRepository->findActivePartners(), fn ($p) => $p->getSlug() === $slug)
            : $this->partnerRepository->findActivePartners();

        if (empty($partners)) {
            $io->warning('Nenhum parceiro ativo encontrado.');
            return Command::SUCCESS;
        }

        foreach ($partners as $partner) {
            $this->tenantContext->setPartner($partner);
            $io->section("[{$partner->getSlug()}] {$partner->getName()}");

            $states = $partner->getCemadenStates();
            if (empty($states)) {
                $io->comment('Parceiro sem estados CEMADEN configurados — pulando.');
                continue;
            }

            $total = 0;
            foreach ($states as $state) {
                try {
                    $records = $this->fetchCemadenState($state);
                    $count   = $this->persistRecords($partner, $records, $dryRun);
                    $total  += $count;
                    $io->writeln("  {$state}: {$count} estações" . ($dryRun ? ' (dry-run)' : ''));

                    $this->logger->info('cemaden.collect.success', [
                        'partner' => $partner->getSlug(),
                        'state'   => $state,
                        'count'   => $count,
                    ]);
                } catch (\Throwable $e) {
                    $io->error("Erro ao coletar {$state}: " . $e->getMessage());
                    $this->logger->error('cemaden.collect.error', [
                        'partner' => $partner->getSlug(),
                        'state'   => $state,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            $io->success("Total: {$total} estações atualizadas.");
        }

        return Command::SUCCESS;
    }

    private function fetchCemadenState(string $state): array
    {
        $response = $this->httpClient->request('GET', self::CEMADEN_URL, [
            'query'   => ['uf' => $state],
            'timeout' => 30,
        ]);

        return $response->toArray();
    }

    private function persistRecords(Partner $partner, array $records, bool $dryRun): int
    {
        $count = 0;
        $now   = new \DateTimeImmutable();

        foreach ($records as $raw) {
            $code = (string) ($raw['codEstacao'] ?? $raw['codigo'] ?? '');
            if (!$code) continue;

            $existing = $this->cemadenRepo->findOneBy(['stationCode' => $code, 'partner' => $partner]);

            $rain  = (float) ($raw['acc1hr'] ?? $raw['chuva'] ?? 0);
            $level = $this->resolveAlertLevel($rain);

            if ($existing) {
                $existing->setAccumulatedRain($rain)
                         ->setAlertLevel($level)
                         ->setMeasuredAt($now);
                if (!$dryRun) $this->cemadenRepo->save($existing, false);
            } else {
                $record = (new CemadenData())
                    ->setPartner($partner)
                    ->setStationCode($code)
                    ->setStationName((string) ($raw['nomeEstacao'] ?? $raw['nome'] ?? $code))
                    ->setMunicipality((string) ($raw['municipio'] ?? ''))
                    ->setState((string) ($raw['uf'] ?? ''))
                    ->setLatitude((float) ($raw['latitude'] ?? 0))
                    ->setLongitude((float) ($raw['longitude'] ?? 0))
                    ->setAccumulatedRain($rain)
                    ->setAlertLevel($level)
                    ->setMeasuredAt($now);

                if (!$dryRun) $this->cemadenRepo->save($record, false);
            }
            $count++;
        }

        if (!$dryRun && $count > 0) {
            $this->cemadenRepo->getEntityManager()->flush();
        }

        return $count;
    }

    private function resolveAlertLevel(float $rain): string
    {
        return match(true) {
            $rain >= 50  => 'VERMELHO',
            $rain >= 30  => 'LARANJA',
            $rain >= 15  => 'AMARELO',
            $rain > 0    => 'VERDE',
            default      => 'SEM_CHUVA',
        };
    }
}
