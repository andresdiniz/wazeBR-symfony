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
    description: 'Coleta dados CEMADEN para estações cadastradas em cemaden_stations.',
)]
class CemadenCollectCommand extends Command
{
    private const CEMADEN_URL = 'http://sjc.salvar.cemaden.gov.br/resources/graficos/interativo/getJson2.php';

    public function __construct(
        private readonly PartnerRepository $partnerRepo,
        private readonly CemadenDataRepository $cemadenRepo,
        private readonly TenantContext $tenantContext,
        private readonly HttpClientInterface $httpClient,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'partner',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Slug do parceiro (omitir = todos os ativos)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('CEMADEN Collect — Multi-Tenant (filtrado por cemaden_stations)');

        $slugFilter = $input->getOption('partner');

        $partners = $slugFilter
            ? array_filter(
                $this->partnerRepo->findActivePartners(),
                static fn (Partner $partner): bool => $partner->getSlug() === $slugFilter,
            )
            : $this->partnerRepo->findActivePartners();

        if (empty($partners)) {
            $io->warning('Nenhum parceiro ativo encontrado.');

            return Command::SUCCESS;
        }

        foreach ($partners as $partner) {
            $this->tenantContext->setPartner($partner);

            $io->section(
                sprintf(
                    'Parceiro: %s [%s]',
                    $partner->getName(),
                    $partner->getSlug(),
                ),
            );

            /*
             * CORREÇÃO:
             * A tabela cemaden_stations possui partner_id, não partner_slug.
             * Portanto, filtramos pelo ID numérico relacionado a partners.id.
             */
            $stations = $this->loadStationsByPartnerId((int) $partner->getId());

            if (empty($stations)) {
                $io->warning(
                    'Nenhuma estação CEMADEN ativa cadastrada em cemaden_stations para este parceiro. Pulando.',
                );

                continue;
            }

            /*
             * Mapa cod_estacao => dados da estação.
             * Ele limita a coleta somente às estações permitidas para o parceiro.
             */
            $stationMap = [];

            foreach ($stations as $station) {
                $stationCode = (string) $station['cod_estacao'];

                if ($stationCode === '') {
                    continue;
                }

                $stationMap[$stationCode] = $station;
            }

            if (empty($stationMap)) {
                $io->warning(
                    'As estações cadastradas não possuem cod_estacao válido. Pulando.',
                );

                continue;
            }

            $io->text(
                sprintf(
                    'Estações autorizadas: %s',
                    implode(', ', array_keys($stationMap)),
                ),
            );

            $states = $partner->getCemadenStates();

            if (empty($states)) {
                $io->warning('Nenhum estado CEMADEN configurado. Pulando.');

                continue;
            }

            $total = 0;

            foreach ($states as $state) {
                try {
                    $count = $this->collectState(
                        $partner,
                        (string) $state,
                        $stationMap,
                    );

                    $io->text(
                        sprintf(
                            'Estado %s: %d novo(s) registro(s).',
                            $state,
                            $count,
                        ),
                    );

                    $total += $count;
                } catch (\Throwable $exception) {
                    $io->error(
                        sprintf(
                            'Erro no estado %s: %s',
                            $state,
                            $exception->getMessage(),
                        ),
                    );
                }
            }

            $io->success(
                sprintf(
                    'Total [%s]: %d novo(s) registro(s) CEMADEN.',
                    $partner->getSlug(),
                    $total,
                ),
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Carrega todas as estações ativas vinculadas ao parceiro.
     *
     * A tabela usa partner_id como chave estrangeira para partners.id.
     * Não usa partner_slug.
     *
     * Inclui estações pluviométricas e hidrológicas, pois o cadastro atual
     * do parceiro possui a estação Rio Bananeiras com station_type = hydrological.
     */
    private function loadStationsByPartnerId(int $partnerId): array
    {
        return $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    id,
                    cod_estacao,
                    nome,
                    municipio,
                    uf,
                    lat,
                    lng,
                    station_type,
                    hydro_url
                FROM cemaden_stations
                WHERE partner_id = :partnerId
                  AND is_active = 1
                  AND station_type IN ('pluviometric', 'hydrological')
                ORDER BY nome ASC, cod_estacao ASC
            SQL,
            [
                'partnerId' => $partnerId,
            ],
        );
    }

    /**
     * Coleta registros de um estado e mantém somente estações autorizadas
     * para o parceiro atual.
     *
     * @param array<string, array<string, mixed>> $stationMap
     */
    private function collectState(
        Partner $partner,
        string $state,
        array &$stationMap,
    ): int {
        $response = $this->httpClient->request('GET', self::CEMADEN_URL, [
            'query' => [
                'uf' => $state,
                'tipo' => 1,
            ],
            'timeout' => 30,
        ]);

        $body = $response->getContent();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return 0;
        }

        $count = 0;

        foreach ($data as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $stationCode = isset($raw['codEstacao'])
                ? trim((string) $raw['codEstacao'])
                : '';

            if ($stationCode === '') {
                continue;
            }

            /*
             * Filtro principal de multi-tenancy:
             * ignora qualquer estação recebida da API que não esteja
             * cadastrada e autorizada para este parceiro.
             */
            if (!isset($stationMap[$stationCode])) {
                continue;
            }

            $measuredAt = $this->resolveMeasuredAt($raw['dataHora'] ?? null);

            $existing = $this->cemadenRepo->findOneBy([
                'stationCode' => $stationCode,
                'partner' => $partner,
                'measuredAt' => $measuredAt,
            ]);

            if ($existing !== null) {
                continue;
            }

            $latitude = isset($raw['latitude']) && $raw['latitude'] !== ''
                ? (float) $raw['latitude']
                : null;

            $longitude = isset($raw['longitude']) && $raw['longitude'] !== ''
                ? (float) $raw['longitude']
                : null;

            $rain = isset($raw['valorMedido']) && $raw['valorMedido'] !== ''
                ? (float) $raw['valorMedido']
                : 0.0;

            $stationRow = $stationMap[$stationCode];

            /*
             * Guarda coordenadas retornadas pela API apenas quando a estação
             * ainda não possui latitude/longitude cadastradas.
             */
            if (
                $latitude !== null
                && $longitude !== null
                && (
                    $stationRow['lat'] === null
                    || $stationRow['lat'] === ''
                    || $stationRow['lng'] === null
                    || $stationRow['lng'] === ''
                )
            ) {
                $this->db->update(
                    'cemaden_stations',
                    [
                        'lat' => $latitude,
                        'lng' => $longitude,
                    ],
                    [
                        'id' => (int) $stationRow['id'],
                    ],
                );

                $stationMap[$stationCode]['lat'] = $latitude;
                $stationMap[$stationCode]['lng'] = $longitude;
            }

            $item = (new CemadenData())
                ->setPartner($partner)
                ->setStationCode($stationCode)
                ->setStationName((string) ($raw['nomeEstacao'] ?? $stationRow['nome'] ?? ''))
                ->setMunicipality((string) ($raw['municipio'] ?? $stationRow['municipio'] ?? ''))
                ->setState($state)
                ->setLatitude($latitude ?? (float) ($stationRow['lat'] ?? 0.0))
                ->setLongitude($longitude ?? (float) ($stationRow['lng'] ?? 0.0))
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

    /**
     * A API pode retornar data em formatos diversos. Mantém a operação
     * resiliente e evita gravar uma data inválida.
     */
    private function resolveMeasuredAt(mixed $value): \DateTimeImmutable
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable) {
                // Usa o instante atual abaixo se a API entregar uma data inválida.
            }
        }

        return new \DateTimeImmutable();
    }

    private function resolveAlertLevel(float $rain): string
    {
        return match (true) {
            $rain >= 50.0 => 'VERMELHO',
            $rain >= 30.0 => 'LARANJA',
            $rain >= 15.0 => 'AMARELO',
            $rain > 0.0 => 'VERDE',
            default => 'SEM_CHUVA',
        };
    }
}
