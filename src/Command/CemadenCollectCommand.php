<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenData;
use App\Entity\Partner;
use App\Repository\CemadenDataRepository;
use App\Repository\PartnerRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
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
    description: 'Coleta dados pluviométricos CEMADEN para todos os parceiros ativos.',
)]
class CemadenCollectCommand extends Command
{
    private const BASE_URL = 'http://sjc.salvar.cemaden.gov.br/resources/graficos/interativo/getJson.php';

    public function __construct(
        private readonly PartnerRepository      $partnerRepository,
        private readonly CemadenDataRepository  $cemadenRepository,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface    $httpClient,
        private readonly TenantContext          $tenantContext,
        private readonly LoggerInterface        $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('partner', 'p', InputOption::VALUE_OPTIONAL, 'Slug do parceiro específico');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Coleta CEMADEN — Multi-Tenant');

        $partnerSlug = $input->getOption('partner');

        $partners = $partnerSlug
            ? array_filter($this->partnerRepository->findActivePartners(), fn ($p) => $p->getSlug() === $partnerSlug)
            : $this->partnerRepository->findActivePartners();

        if (empty($partners)) {
            $io->warning('Nenhum parceiro ativo encontrado.');
            return Command::SUCCESS;
        }

        foreach ($partners as $partner) {
            $this->tenantContext->setPartner($partner);
            $io->section("Parceiro: {$partner->getName()} [{$partner->getSlug()}]");

            $states = $partner->getCemadenStates();
            if (empty($states)) {
                $io->comment('Parceiro sem estados CEMADEN configurados. Pulando.');
                continue;
            }

            foreach ($states as $state) {
                try {
                    $saved = $this->collectForState($state, $partner, $io);
                    $io->text(" → {$state}: {$saved} estações salvas/atualizadas");
                } catch (\Throwable $e) {
                    $this->logger->error('Falha na coleta CEMADEN', [
                        'partner' => $partner->getSlug(),
                        'state'   => $state,
                        'error'   => $e->getMessage(),
                    ]);
                    $io->error("{$state}: {$e->getMessage()}");
                }
            }
        }

        $io->success('Coleta CEMADEN concluída.');
        return Command::SUCCESS;
    }

    private function collectForState(string $state, Partner $partner, SymfonyStyle $io): int
    {
        $url = self::BASE_URL . '?uf=' . strtoupper($state);
        $io->text("GET {$url}");

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $rows  = $response->toArray();
        $saved = 0;

        foreach ($rows as $row) {
            $stationCode = (string) ($row['codEstacao'] ?? '');
            if (!$stationCode) continue;

            // Upsert: atualiza se já existe (dados recentes), insere se não existe
            $existing = $this->cemadenRepository->findOneBy(['stationCode' => $stationCode, 'partner' => $partner]);
            $entity   = $existing ?? new CemadenData();

            $entity
                ->setPartner($partner)
                ->setStationCode($stationCode)
                ->setStationName((string) ($row['nomeEstacao'] ?? ''))
                ->setMunicipality((string) ($row['municipio'] ?? ''))
                ->setState(strtoupper((string) ($row['uf'] ?? $state)))
                ->setLatitude((float) ($row['latitude'] ?? 0))
                ->setLongitude((float) ($row['longitude'] ?? 0))
                ->setAccumulatedRain((float) ($row['acc1hr'] ?? $row['acumulado'] ?? 0))
                ->setAlertLevel($this->resolveAlertLevel((float) ($row['acc1hr'] ?? 0)))
                ->setMeasuredAt(new \DateTimeImmutable());

            if (!$existing) {
                $this->em->persist($entity);
            }

            $saved++;
        }

        $this->em->flush();
        return $saved;
    }

    private function resolveAlertLevel(float $rain): string
    {
        return match (true) {
            $rain >= 50  => 'VERMELHO',
            $rain >= 30  => 'LARANJA',
            $rain >= 15  => 'AMARELO',
            $rain > 0    => 'VERDE',
            default      => 'SEM_CHUVA',
        };
    }
}
