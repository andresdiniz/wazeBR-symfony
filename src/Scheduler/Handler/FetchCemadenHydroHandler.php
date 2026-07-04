<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Entity\CemadenHydroData;
use App\Repository\CemadenHydroDataRepository;
use App\Repository\PartnerRepository;
use App\Scheduler\Message\FetchCemadenHydroMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Busca dados hidrológicos de todas as estações CEMADEN ativas
 * cujo station_type = 'hydrological' e hydro_url não é NULL.
 *
 * URL da API:
 *   https://resources.cemaden.gov.br/graficos/cemaden/hidro/resources/json/MedidaResource.php
 *   ?est={EST_ID}&sen=20&pag=24
 *
 * O campo hydro_url em cemaden_stations armazena a URL completa ou o prefixo
 * (até o "?"), seguida dos parâmetros.  O handler usa a hydro_url como-está.
 *
 * Nível de alerta calculado a partir das cotas:
 *   valor >= cota_transbordamento → transbordamento
 *   valor >= cota_alerta          → alerta
 *   valor >= cota_atencao         → atencao
 *   else                          → normal
 */
#[AsMessageHandler]
final class FetchCemadenHydroHandler
{
    private const NETWORK_ERROR_PATTERNS = [
        'Could not resolve host',
        'Recv failure',
        'Connection was reset',
        'Connection refused',
        'Connection timed out',
        'timed out',
        'SSL',
        'Network is unreachable',
    ];

    public function __construct(
        private readonly HttpClientInterface         $httpClient,
        private readonly EntityManagerInterface      $em,
        private readonly CemadenHydroDataRepository  $hydroRepo,
        private readonly PartnerRepository            $partnerRepo,
        private readonly LoggerInterface              $logger,
    ) {}

    public function __invoke(FetchCemadenHydroMessage $message): void
    {
        // Busca estações hidrológicas ativas via DBAL direto (sem entidade CemadenStation)
        $conn = $this->em->getConnection();

        $sql = "SELECT cod_estacao, nome, municipio, uf, hydro_url, partner_slug
                FROM cemaden_stations
                WHERE station_type = 'hydrological'
                  AND is_active = 1
                  AND hydro_url IS NOT NULL
                  AND hydro_url != ''";

        if ($message->partnerSlug !== null) {
            $sql .= " AND partner_slug = " . $conn->quote($message->partnerSlug);
        }

        $stations = $conn->fetchAllAssociative($sql);

        if (empty($stations)) {
            $this->logger->info('[CemadenHydro] Nenhuma estação hidrológica ativa encontrada.');
            return;
        }

        $totalSaved   = 0;
        $totalSkipped = 0;
        $totalErrors  = 0;

        foreach ($stations as $station) {
            $url    = $station['hydro_url'];
            $code   = $station['cod_estacao'];
            $name   = $station['nome'];
            $city   = $station['municipio'];
            $uf     = $station['uf'];
            $slug   = $station['partner_slug'] ?? null;

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 15,
                    'headers' => ['Accept' => 'application/json'],
                ]);

                $data = $response->toArray(throw: false);

                if (!is_array($data) || empty($data)) {
                    $this->logger->warning('[CemadenHydro] Resposta vazia ou inválida', [
                        'station' => $code,
                        'url'     => $url,
                    ]);
                    continue;
                }

                // Busca o parceiro uma vez por estação
                $partner = $slug ? $this->partnerRepo->findOneBy(['slug' => $slug]) : null;

                $saved = 0;

                foreach ($data as $row) {
                    if (!isset($row['datahora'], $row['valor'])) {
                        continue;
                    }

                    $measuredAt = \DateTimeImmutable::createFromFormat(
                        'Y-m-d H:i:s',
                        $row['datahora']
                    );

                    if ($measuredAt === false) {
                        continue;
                    }

                    // Idempotência: ignora duplicata
                    if ($this->hydroRepo->existsByStationAndTime($code, $measuredAt)) {
                        $totalSkipped++;
                        continue;
                    }

                    $nivel    = isset($row['valor']) ? (float) $row['valor'] : null;
                    $atencao  = isset($row['cota_atencao']) ? (float) $row['cota_atencao'] : null;
                    $alerta   = isset($row['cota_alerta']) ? (float) $row['cota_alerta'] : null;
                    $transb   = isset($row['cota_transbordamento']) ? (float) $row['cota_transbordamento'] : null;

                    $alertLevel = $this->calculateAlertLevel($nivel, $atencao, $alerta, $transb);

                    $entry = (new CemadenHydroData())
                        ->setStationCode($code)
                        ->setStationName($row['estacao'] ?? $name)
                        ->setMunicipality($row['cidade'] ?? $city)
                        ->setState($row['uf'] ?? $uf)
                        ->setWaterLevel($nivel)
                        ->setOffsetValue(isset($row['offset']) ? (float) $row['offset'] : null)
                        ->setQualificacao($row['qualificacao'] ?? null)
                        ->setCotaAtencao($atencao)
                        ->setCotaAlerta($alerta)
                        ->setCotaTransbordamento($transb)
                        ->setAlertLevel($alertLevel)
                        ->setPartner($partner)
                        ->setMeasuredAt($measuredAt);

                    $this->em->persist($entry);
                    $saved++;
                }

                if ($saved > 0) {
                    $this->em->flush();
                }

                $totalSaved += $saved;

                $this->logger->info('[CemadenHydro] Estação processada', [
                    'station' => $code,
                    'saved'   => $saved,
                    'skipped' => count($data) - $saved,
                ]);

            } catch (\Throwable $e) {
                $totalErrors++;
                $msg = $e->getMessage();

                $this->logger->error('[CemadenHydro] Erro ao buscar estação', [
                    'station' => $code,
                    'url'     => $url,
                    'error'   => $msg,
                ]);

                if ($this->isNetworkError($msg)) {
                    $this->logger->warning(
                        '[CemadenHydro] Falha de rede — ciclo abortado.',
                        ['error' => $msg]
                    );
                    break;
                }
            }
        }

        $this->logger->info('[CemadenHydro] Ciclo concluído', [
            'total_saved'   => $totalSaved,
            'total_skipped' => $totalSkipped,
            'total_errors'  => $totalErrors,
        ]);
    }

    private function calculateAlertLevel(
        ?float $nivel,
        ?float $atencao,
        ?float $alerta,
        ?float $transb,
    ): ?string {
        if ($nivel === null) {
            return null;
        }

        if ($transb !== null && $nivel >= $transb) {
            return 'transbordamento';
        }

        if ($alerta !== null && $nivel >= $alerta) {
            return 'alerta';
        }

        if ($atencao !== null && $nivel >= $atencao) {
            return 'atencao';
        }

        return 'normal';
    }

    private function isNetworkError(string $message): bool
    {
        foreach (self::NETWORK_ERROR_PATTERNS as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}
