<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CemadenData;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CemadenService
{
    /**
     * URL base para dados pluviométricos (chuva) — via API key + state.
     * Ex: https://apicemaden.defesa.civil.gov.br/apicemaden/estacoes?key=...&state=MG
     */
    private const URL_CHUVA = null; // usa $this->apiUrl injetada

    /**
     * URL base para dados hidrológicos (nível do rio) — endpoint público CEMADEN.
     * Parâmetros: est=<código da estação>, sen=20 (sensor de nível), pag=<qtd registros>
     */
    private const URL_HIDRO = 'https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/MedidaResource.php';

    public function __construct(
        private readonly HttpClientInterface    $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface        $cemadenLogger,
        private readonly string                 $apiUrl,
        private readonly string                 $apiKey,
    ) {}

    /**
     * Coleta dados pluviométricos (chuva acumulada) via API key.
     */
    public function collectData(string $state = 'MG'): array
    {
        $this->cemadenLogger->info('Coletando dados CEMADEN (chuva)', ['state' => $state]);

        $response = $this->httpClient->request('GET', $this->apiUrl, [
            'query' => [
                'key'   => $this->apiKey,
                'state' => $state,
            ],
        ]);

        $data    = $response->toArray();
        $records = $data['body'] ?? [];
        $saved   = 0;

        foreach ($records as $record) {
            $station = (new CemadenData())
                ->setStationCode($record['codEstacao'] ?? 'N/A')
                ->setStationName($record['nomeEstacao'] ?? 'N/A')
                ->setMunicipality($record['municipio'] ?? 'N/A')
                ->setState($state)
                ->setLatitude((float) ($record['latitude'] ?? 0))
                ->setLongitude((float) ($record['longitude'] ?? 0))
                ->setAccumulatedRain(isset($record['valorMedida']) ? (float) $record['valorMedida'] : null)
                ->setAlertLevel($record['statusAlerta'] ?? null)
                ->setMeasuredAt(new \DateTimeImmutable($record['dataHora'] ?? 'now'));

            $this->entityManager->persist($station);
            $saved++;
        }

        $this->entityManager->flush();

        $this->cemadenLogger->info('Dados CEMADEN (chuva) coletados', ['total_api' => count($records), 'salvos' => $saved]);
        return ['total' => count($records), 'saved' => $saved];
    }

    /**
     * Coleta nível do rio via endpoint hidrológico público do CEMADEN.
     *
     * @param string $estacaoCode  Código da estação. Ex: "6622"
     * @param int    $sensor       Código do sensor. 20 = nível do rio.
     * @param int    $pagina       Quantidade de registros retornados.
     *
     * Estrutura do JSON retornado:
     * [
     *   { "codEstacao": "6622", "nomeEstacao": "...", "municipio": "...",
     *     "latitude": "-20.xx", "longitude": "-43.xx",
     *     "valorMedida": "1.23",   <- nível em metros
     *     "dataHora": "2024-01-15 14:00:00",
     *     "statusAlerta": "verde" },
     *   ...
     * ]
     */
    public function collectWaterLevel(
        string $estacaoCode,
        int    $sensor = 20,
        int    $pagina = 24,
        string $state  = 'MG',
    ): array {
        $this->cemadenLogger->info('Coletando nível do rio CEMADEN', [
            'estacao' => $estacaoCode,
            'sensor'  => $sensor,
            'pagina'  => $pagina,
        ]);

        $response = $this->httpClient->request('GET', self::URL_HIDRO, [
            'query' => [
                'est' => $estacaoCode,
                'sen' => $sensor,
                'pag' => $pagina,
            ],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        // O endpoint retorna JSON direto como array de objetos
        $records = $response->toArray();
        $saved   = 0;

        foreach ($records as $record) {
            $codEstacao  = (string) ($record['codEstacao']  ?? $estacaoCode);
            $nomeEstacao = (string) ($record['nomeEstacao'] ?? 'Estação ' . $estacaoCode);
            $municipio   = (string) ($record['municipio']  ?? 'N/A');
            $latitude    = isset($record['latitude'])  ? (float) $record['latitude']  : 0.0;
            $longitude   = isset($record['longitude']) ? (float) $record['longitude'] : 0.0;
            $valor       = isset($record['valorMedida']) ? (float) $record['valorMedida'] : null;
            $alerta      = $record['statusAlerta'] ?? null;
            $dataHora    = $record['dataHora'] ?? 'now';

            try {
                $dt = new \DateTimeImmutable($dataHora);
            } catch (\Throwable) {
                $dt = new \DateTimeImmutable();
            }

            $entry = (new CemadenData())
                ->setStationCode($codEstacao)
                ->setStationName($nomeEstacao)
                ->setMunicipality($municipio)
                ->setState($state)
                ->setLatitude($latitude)
                ->setLongitude($longitude)
                ->setWaterLevel($valor)      // <- nível do rio (metros)
                ->setAccumulatedRain(null)   // não é dado de chuva
                ->setAlertLevel($alerta)
                ->setMeasuredAt($dt);

            $this->entityManager->persist($entry);
            $saved++;
        }

        $this->entityManager->flush();

        $this->cemadenLogger->info('Nível do rio CEMADEN coletado', [
            'estacao'   => $estacaoCode,
            'total_api' => count($records),
            'salvos'    => $saved,
        ]);

        return ['total' => count($records), 'saved' => $saved];
    }

    public function getActiveAlerts(): array
    {
        return $this->entityManager->getRepository(CemadenData::class)->findActiveAlerts();
    }
}
