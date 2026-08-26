<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenHydroData;
use App\Entity\CemadenStation;
use App\Repository\CemadenStationRepository;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'cemaden:collect-hydro',
    description: 'Coleta níveis de rios das estações hidrológicas CEMADEN e persiste na tabela cemaden_hydro_data.',
)]
class CemadenCollectHydroCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CemadenStationRepository $stationRepo,
        private readonly PartnerRepository      $partnerRepo,
        private readonly HttpClientInterface    $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'partner',
                'p',
                InputOption::VALUE_REQUIRED,
                'ID do parceiro (obrigatório)',
            )
            ->addOption(
                'station',
                's',
                InputOption::VALUE_OPTIONAL,
                'ID da estação hidrológica (omitir = todas as ativas do parceiro)',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Mostra os dados sem salvar no banco',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $partnerId = (int) $input->getOption('partner');
        $stationId = $input->getOption('station');

        $io->title('CEMADEN Collect Hydro' . ($dryRun ? ' [DRY-RUN]' : ''));

        $partner = $this->partnerRepo->find($partnerId);
        if (!$partner) {
            $io->error('Parceiro com ID ' . $partnerId . ' não encontrado.');
            return Command::FAILURE;
        }

        $io->text('Parceiro: ' . $partner->getName() . ' (ID: ' . $partner->getId() . ')');

        $stations = $stationId
            ? [$this->stationRepo->findActiveHydrologicalByIdAndPartner((int) $stationId, $partner)]
            : $this->stationRepo->findActiveHydrologicalByPartner($partner);

        $stations = array_filter($stations);

        if (empty($stations)) {
            $io->warning('Nenhuma estação hidrológica ativa com URL configurada encontrada para este parceiro.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Estações encontradas: %d', count($stations)));
        $totalInserted = 0;

        foreach ($stations as $station) {
            $io->section(sprintf(
                '[%s] %s — %s/%s',
                $station->getPartner()->getSlug(),
                $station->getNome(),
                $station->getMunicipio(),
                $station->getUf(),
            ));

            try {
                $inserted = $this->processStation($station, $io, $dryRun);
                $io->text("  ✓ {$inserted} novo(s) registro(s) inserido(s).");
                $totalInserted += $inserted;
            } catch (\Throwable $e) {
                $io->error(sprintf('  Erro: %s', $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Concluído. Total inserido: %d registro(s)%s.',
            $totalInserted,
            $dryRun ? ' (dry-run, nada salvo)' : '',
        ));

        return Command::SUCCESS;
    }

    private function sanitizeUrl(string $url): string
    {
        return trim(preg_replace('/[\r\n\t]+/', '', $url));
    }

    private function processStation(CemadenStation $station, SymfonyStyle $io, bool $dryRun): int
    {
        $url = $this->sanitizeUrl($station->getHydroUrl());

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $body = $response->getContent();
        $rows = json_decode($body, true);

        if (!is_array($rows) || empty($rows)) {
            $io->text('  Nenhum dado retornado pela API.');
            return 0;
        }

        $partner = $station->getPartner();
        $inserted = 0;

        foreach ($rows as $raw) {
            $measuredAt = $raw['datahora'] ?? null;
            if (!$measuredAt) continue;

            // Evita duplicatas
            $existing = $this->em->getRepository(CemadenHydroData::class)->findOneBy([
                'stationCode' => $station->getCodEstacao(),
                'measuredAt'  => new \DateTimeImmutable($measuredAt),
            ]);
            if ($existing) continue;

            // --- CÁLCULO CORRETO DO NÍVEL ---
            // offset = distância do fundo do rio até o sensor
            // valor  = distância da lâmina d'água até o sensor
            // nível  = offset - valor (metros de água acima do fundo)
            $offset = isset($raw['offset']) ? (float) $raw['offset'] : null;
            $valor  = isset($raw['valor'])  ? (float) $raw['valor']  : null;
            $waterLevel = ($offset !== null && $valor !== null) ? round($offset - $valor, 3) : null;

            // Extrai cotas
            $cotaAtencao = isset($raw['cota_atencao']) ? (float) $raw['cota_atencao'] : null;
            $cotaAlerta  = isset($raw['cota_alerta'])  ? (float) $raw['cota_alerta']  : null;
            $cotaTransb  = isset($raw['cota_transbordamento']) ? (float) $raw['cota_transbordamento'] : null;

            // Determina alerta com base no nível calculado
            $alertLevel = $this->determineAlertLevel($waterLevel, $cotaAtencao, $cotaAlerta, $cotaTransb);

            // Cria entidade
            $hydro = new CemadenHydroData();
            $hydro->setStationCode($station->getCodEstacao());
            $hydro->setStationName($station->getNome());
            $hydro->setMunicipality($station->getMunicipio());
            $hydro->setState($station->getUf());
            $hydro->setWaterLevel($waterLevel);      // nível real em metros
            $hydro->setOffsetValue($offset);         // guarda offset para referência
            $hydro->setQualificacao($raw['qualificacao'] ?? null);
            $hydro->setCotaAtencao($cotaAtencao);
            $hydro->setCotaAlerta($cotaAlerta);
            $hydro->setCotaTransbordamento($cotaTransb);
            $hydro->setAlertLevel($alertLevel);
            $hydro->setPartner($partner);
            $hydro->setMeasuredAt(new \DateTimeImmutable($measuredAt));

            if ($io->isVerbose()) {
                $io->text(sprintf(
                    '  %s | offset=%.3f | valor=%.3f | nível=%.3f | alerta=%s',
                    $measuredAt,
                    $offset ?? 0,
                    $valor ?? 0,
                    $waterLevel ?? 0,
                    $alertLevel ?? 'normal',
                ));
            }

            if (!$dryRun) {
                $this->em->persist($hydro);
                $this->em->flush();
            }

            $inserted++;
        }

        return $inserted;
    }

    private function determineAlertLevel(?float $level, ?float $cotaAtencao, ?float $cotaAlerta, ?float $cotaTransb): ?string
    {
        if ($level === null) {
            return 'normal';
        }

        if ($cotaTransb !== null && $level >= $cotaTransb) {
            return 'transbordamento';
        }
        if ($cotaAlerta !== null && $level >= $cotaAlerta) {
            return 'alerta';
        }
        if ($cotaAtencao !== null && $level >= $cotaAtencao) {
            return 'atencao';
        }

        return 'normal';
    }
}
