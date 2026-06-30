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

            // Carrega estações pluviométricas ativas deste parceiro (com id para atualizar lat/lng)
            $stations = $this->loadPluviometricStations($partner->getSlug());

            if (empty($stations)) {
                $io->warning('Nenhuma estação pluviométrica ativa cadastrada em cemaden_stations. Pulando.');
                continue;
            }

            // Monta mapa cod_estacao → id (para atualizar lat/lng depois)
            $stationMap = [];
            foreach ($stations as $s) {
                $stationMap[$s['cod_estacao']] = $s;
            }

            $io->text(sprintf('  Estações autorizadas: %s', implode(', ', array_keys($stationMap))));

            $states = $partner->getCemadenStates();
            if (empty($states)) {
                $io->warning('Nenhum estado CEMADEN configurado. Pulando.');
                continue;
            }

            $total = 0;
            foreach ($states as $state) {
                try {
                    $count = $this->collectState($partner, $state, $stationMap);
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
     * Carrega estações pluviométricas ativas de um parceiro com id, cod_estacao e lat/lng atuais.
     */
    private function loadPluviometricStations(string $partnerSlug): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, cod_estacao, lat, lng
             FROM cemaden_stations
             WHERE partner_slug = ? AND station_type = 'pluviometric' AND is_active = 1",
            [$partnerSlug],
        );
    }

    private function collectState(Partner $partner, string $state, array $stationMap): int
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
            if (!isset($stationMap[$stationCode])) continue;

            $measuredAt = isset($raw['dataHora'])
                ? new \DateTimeImmutable($raw['dataHora'])
                : new \DateTimeImmutable();

            $existing = $this->cemadenRepo->findOneBy([
                'stationCode' => $stationCode,
                'partner'     => $partner,
                'measuredAt'  => $measuredAt,
            ]);

            if ($existing) continue;

            $lat  = isset($raw['latitude'])  ? (float) $raw['latitude']  : null;
            $lng  = isset($raw['longitude']) ? (float) $raw['longitude'] : null;
            $rain = (float) ($raw['valorMedido'] ?? 0);

            // Atualiza lat/lng na tabela cemaden_stations se ainda não tiver coordenada
            $stationRow = $stationMap[$stationCode];
            if ($lat !== null && $lng !== null &&
                (empty($stationRow['lat']) || empty($stationRow['lng']))) {
                $this->db->update('cemaden_stations', [
                    'lat' => $lat,
                    'lng' => $lng,
                ], ['id' => (int) $stationRow['id']]);
                // Atualiza o mapa local para não repetir o UPDATE
                $stationMap[$stationCode]['lat'] = $lat;
                $stationMap[$stationCode]['lng'] = $lng;
            }

            $item = (new CemadenData())
                ->setPartner($partner)
                ->setStationCode((string) $stationCode)
                ->setStationName($raw['nomeEstacao'] ?? '')
                ->setMunicipality($raw['municipio'] ?? '')
                ->setState($state)
                ->setLatitude($lat ?? 0.0)
                ->setLongitude($lng ?? 0.0)
                ->setAccumulatedRain($rain)
                ->setAlertLevel($this->resolveAlertLevel($rain))
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
