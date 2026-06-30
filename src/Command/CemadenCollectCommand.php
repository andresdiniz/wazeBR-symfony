<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenData;
use App\Entity\Partner;
use App\Repository\CemadenDataRepository;
use App\Repository\PartnerRepository;
use App\Service\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'cemaden:collect',
    description: 'Coleta dados CEMADEN pluviométricos para estações cadastradas em cemaden_stations.',
)]
class CemadenCollectCommand extends Command
{
    private const CEMADEN_URL = 'http://sjc.salvar.cemaden.gov.br/resources/graficos/interativo/getJson2.php';

    public function __construct(
        private readonly PartnerRepository     $partnerRepo,
        private readonly CemadenDataRepository $cemadenRepo,
        private readonly TenantContext         $tenantContext,
        private readonly HttpClientInterface   $httpClient,
        private readonly Connection            $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('partner', 'p', InputOption::VALUE_OPTIONAL,
            'Slug do parceiro (omitir = todos os ativos)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('CEMADEN Collect — Multi-Tenant (filtrado por cemaden_stations)');

        $slugFilter = $input->getOption('partner');

        $partners = $slugFilter
            ? array_filter(
                $this->partnerRepo->findActivePartners(),
                fn (Partner $p) => $p->getSlug() === $slugFilter,
            )
            : $this->partnerRepo->findActivePartners();

        if (empty($partners)) {
            $io->warning('Nenhum parceiro ativo encontrado.');
            return Command::SUCCESS;
        }

        foreach ($partners as $partner) {
            $this->tenantContext->setPartner($partner);
            $io->section("Parceiro: {$partner->getName()} [{$partner->getSlug()}]");

            // Carrega estações pluviométricas ativas deste parceiro
            $allowedCodes = $this->loadAllowedCodes($partner->getSlug());

            if (empty($allowedCodes)) {
                $io->warning('Nenhuma estação pluviométrica ativa cadastrada em cemaden_stations. Pulando.');
                continue;
            }

            $io->text(sprintf('  Estações autorizadas: %s', implode(', ', $allowedCodes)));

            $states = $partner->getCemadenStates();
            if (empty($states)) {
                $io->warning('Nenhum estado CEMADEN configurado. Pulando.');
                continue;
            }

            $total = 0;
            foreach ($states as $state) {
                try {
                    $count = $this->collectState($partner, $state, $allowedCodes);
                    $io->text("  Estado {$state}: {$count} novos registros.");
                    $total += $count;
                } catch (\Throwable $e) {
                    $io->error("  Erro no estado {$state}: " . $e->getMessage());
                }
            }

            $io->success("Total [{$partner->getSlug()}]: {$total} novos registros CEMADEN.");
        }

        return Command::SUCCESS;
    }

    /**
     * Carrega os cod_estacao pluviométricos ativos de um parceiro.
     * Retorna um array associativo: ['311830410H' => true, ...]
     */
    private function loadAllowedCodes(string $partnerSlug): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT cod_estacao FROM cemaden_stations
             WHERE partner_slug = ? AND station_type = 'pluviometric' AND is_active = 1",
            [$partnerSlug],
        );

        $codes = [];
        foreach ($rows as $row) {
            $codes[$row['cod_estacao']] = true;
        }

        return $codes;
    }

    private function collectState(Partner $partner, string $state, array $allowedCodes): int
    {
        $response = $this->httpClient->request('GET', self::CEMADEN_URL, [
            'query'   => ['uf' => $state, 'tipo' => 1],
            'timeout' => 30,
        ]);

        $body = $response->getContent();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return 0;
        }

        $count = 0;
        foreach ($data as $raw) {
            $stationCode = $raw['codEstacao'] ?? null;
            if (!$stationCode) continue;

            // ← Filtro principal: ignora estações não cadastradas
            if (!isset($allowedCodes[$stationCode])) continue;

            $measuredAt = isset($raw['dataHora'])
                ? new \DateTimeImmutable($raw['dataHora'])
                : new \DateTimeImmutable();

            $existing = $this->cemadenRepo->findOneBy([
                'stationCode' => $stationCode,
                'partner'     => $partner,
                'measuredAt'  => $measuredAt,
            ]);

            if ($existing) continue;

            $rain  = (float) ($raw['valorMedido'] ?? 0);
            $level = $this->resolveAlertLevel($rain);

            $item = (new CemadenData())
                ->setPartner($partner)
                ->setStationCode((string) $stationCode)
                ->setStationName($raw['nomeEstacao'] ?? '')
                ->setMunicipality($raw['municipio'] ?? '')
                ->setState($state)
                ->setLatitude((float) ($raw['latitude'] ?? 0))
                ->setLongitude((float) ($raw['longitude'] ?? 0))
                ->setAccumulatedRain($rain)
                ->setAlertLevel($level)
                ->setMeasuredAt($measuredAt);

            $this->cemadenRepo->save($item, false);
            $count++;
        }

        if ($count > 0) {
            $this->cemadenRepo->getEntityManager()->flush();
        }

        return $count;
    }

    private function resolveAlertLevel(float $rain): string
    {
        return match (true) {
            $rain >= 50.0 => 'VERMELHO',
            $rain >= 30.0 => 'LARANJA',
            $rain >= 15.0 => 'AMARELO',
            $rain > 0     => 'VERDE',
            default       => 'SEM_CHUVA',
        };
    }
}
