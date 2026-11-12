<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\WazeTvtIrregularity;
use App\Repository\PartnerRepository;
use App\Repository\PartnerApiLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:fetch:waze:tvt-irregularities', description: 'Importa irregularities da raiz dos feeds TVT dos parceiros ativos.')]
final class ImportTvtRootIrregularitiesCommand extends Command
{
    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly PartnerApiLinkRepository $links,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'ID interno opcional; omitido processa parceiros ativos com links TVT.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'JSON local; exige --partner e nao desativa ausentes.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Valida e conta sem gravar no banco.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawId = $input->getOption('partner');
        $file = $input->getOption('file');
        $id = null;

        if ($rawId !== null) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id === false) {
                $io->error('--partner deve ser um ID interno positivo.');

                return Command::INVALID;
            }
        }

        if ($file !== null && $id === null) {
            $io->error('--file exige --partner para definir o vinculo do arquivo.');

            return Command::INVALID;
        }

        $groups = [];

        try {
            if ($file !== null) {
                $groups[$id] = [];
            } else {
                foreach ($this->links->findAllByType('TVT') as $link) {
                    $partner = $link->getPartner();

                    if ($partner === null || $partner->getId() === null || !$partner->isActive()) {
                        continue;
                    }

                    if ($id !== null && $partner->getId() !== $id) {
                        continue;
                    }

                    $groups[$partner->getId()][] = $link;
                }

                if ($id !== null) {
                    $partner = $this->partners->find($id);

                    if ($partner === null || !$partner->isActive()) {
                        $io->error('Parceiro inexistente ou inativo.');

                        return Command::INVALID;
                    }
                }
            }
        } catch (\Throwable $exception) {
            $io->error('Nao foi possivel carregar parceiros e links TVT: ' . $exception::class);

            return Command::FAILURE;
        }

        if ($groups === []) {
            $io->note('Nenhum parceiro ativo com link TVT para processar.');

            return Command::SUCCESS;
        }

        $io->title('Importacao de irregularidades TVT');
        $io->note(sprintf('%d parceiro(s) selecionado(s).', count($groups)));

        $failures = 0;

        foreach ($groups as $partnerId => $links) {
            if (!$this->em->isOpen()) {
                $io->error('EntityManager fechado apos falha; execucao interrompida para nao processar outros parceiros em estado invalido.');

                return Command::FAILURE;
            }

            try {
                $partner = $this->partners->find($partnerId);

                if ($partner === null || !$partner->isActive()) {
                    $io->note(sprintf('Parceiro %d indisponivel ou inativo; ignorado.', $partnerId));

                    continue;
                }

                $io->section(sprintf('Parceiro %d - %s', $partnerId, $partner->getName()));
                $result = $this->importPartner($partner, $links, $file, (bool) $input->getOption('dry-run'), $io);

                if ($result !== Command::SUCCESS) {
                    ++$failures;
                }
            } catch (\Throwable $exception) {
                ++$failures;
                $io->error(sprintf('Parceiro %d nao processado: %s.', $partnerId, $exception::class));

                if ($this->em->isOpen()) {
                    $this->em->clear();
                }
            }
        }

        if ($failures > 0) {
            $io->error(sprintf('%d parceiro(s) com falha. Os demais lotes confirmados foram preservados.', $failures));

            return Command::FAILURE;
        }

        $io->success('Execucao concluida. Parceiros com trava ocupada foram ignorados e poderao ser processados no proximo ciclo.');

        return Command::SUCCESS;
    }

    private function importPartner(Partner $partner, array $links, ?string $file, bool $dryRun, SymfonyStyle $io): int
    {
        $id = $partner->getId();
        $lock = $this->locks->createLock('waze:tvt:partner:' . $id, 600.0);

        if (!$lock->acquire()) {
            $io->note('Outra coleta TVT detem a trava deste parceiro; ignorado neste ciclo.');

            return Command::SUCCESS;
        }

        $transaction = false;

        try {
            $payloads = [];

            if ($file !== null) {
                if (!is_file($file) || !is_readable($file) || filesize($file) > 20000000) {
                    throw new \RuntimeException('Arquivo invalido, inacessivel ou maior que 20 MB.');
                }

                $payloads[] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } else {
                foreach ($links as $link) {
                    $url = (string) $link->getUrl();

                    if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                        throw new \RuntimeException('Protocolo do link TVT invalido.');
                    }

                    $payloads[] = $this->http
                        ->request('GET', $url, [
                            'timeout' => 30,
                            'max_duration' => 60,
                            'headers' => ['Accept' => 'application/json'],
                        ])
                        ->toArray();

                    $lock->refresh();
                }
            }

            if ($payloads === []) {
                throw new \RuntimeException('Nenhum feed TVT encontrado para o parceiro.');
            }

            $items = [];
            $authoritative = $file === null;
            $received = 0;

            foreach ($payloads as $payload) {
                if (!is_array($payload)) {
                    throw new \RuntimeException('A raiz do JSON deve ser um objeto.');
                }

                if (!array_key_exists('irregularities', $payload)) {
                    $authoritative = false;

                    continue;
                }

                $list = $payload['irregularities'];

                if (!is_array($list) || !array_is_list($list)) {
                    throw new \RuntimeException('irregularities deve ser uma lista; nenhuma alteracao foi aplicada.');
                }

                foreach ($list as $data) {
                    if (!is_array($data)) {
                        throw new \RuntimeException('Irregularidade invalida; nenhuma alteracao foi aplicada.');
                    }

                    ++$received;
                    $items[$this->fingerprint($data)] = $data;
                }
            }

            $io->table(
                ['Parceiro', 'Recebidas', 'Identidades distintas', 'Pode desativar ausentes'],
                [[$id, $received, count($items), $authoritative ? 'sim' : 'nao']],
            );

            if ($dryRun) {
                $io->note('Simulacao: nenhum registro gravado ou desativado.');

                return Command::SUCCESS;
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $repository = $this->em->getRepository(WazeTvtIrregularity::class);
            $existing = $repository->createQueryBuilder('i')
                ->where('i.partner = :partner')
                ->andWhere('i.route IS NULL')
                ->andWhere('i.subRoute IS NULL')
                ->andWhere('i.contentHash LIKE :prefix')
                ->setParameter('partner', $partner)
                ->setParameter('prefix', 'rootv1:%')
                ->getQuery()
                ->getResult();

            $byHash = [];

            foreach ($existing as $entity) {
                $byHash[$entity->getContentHash()] = $entity;
            }

            $this->em->getConnection()->beginTransaction();
            $transaction = true;
            $created = 0;
            $updated = 0;
            $reactivated = 0;
            $deactivated = 0;

            foreach ($items as $hash => $data) {
                $entity = $byHash[$hash] ?? null;

                if ($entity === null) {
                    $entity = (new WazeTvtIrregularity())
                        ->setPartner($partner)
                        ->setRoute(null)
                        ->setSubRoute(null)
                        ->setWazeRouteId(null)
                        ->setWazeSubRouteId(null)
                        ->setContentHash($hash)
                        ->setRecordedAt($now);

                    $this->em->persist($entity);
                    ++$created;
                } else {
                    ++$updated;

                    if (!$entity->isActive()) {
                        ++$reactivated;
                    }
                }

                $lead = is_array($data['leadAlert'] ?? null) ? $data['leadAlert'] : [];
                $position = $this->position($lead['position'] ?? null) ?? $this->firstPoint($data['line'] ?? null);

                $entity
                    ->setType($this->text($data['type'] ?? null))
                    ->setSubtype($this->text($data['subtype'] ?? $lead['subType'] ?? null))
                    ->setSeverity($this->severity($data['jamLevel'] ?? $lead['jamLevel'] ?? null))
                    ->setReportedTime($this->timestamp($data['updateTime'] ?? $lead['updateTime'] ?? null))
                    ->setDescription($this->text($data['description'] ?? null))
                    ->setStreet($this->text($data['street'] ?? $lead['street'] ?? null))
                    ->setCity($this->text($data['city'] ?? $lead['city'] ?? null))
                    ->setState($this->text($data['state'] ?? $lead['state'] ?? null, 2))
                    ->setLatitude($position['lat'] ?? null)
                    ->setLongitude($position['lng'] ?? null)
                    ->setPayload($data)
                    ->setIsActive(true)
                    ->setLastSeenAt($now)
                    ->setUpdatedAt($now);
            }

            if ($authoritative) {
                foreach ($byHash as $hash => $entity) {
                    if (!isset($items[$hash]) && $entity->isActive()) {
                        $entity
                            ->setIsActive(false)
                            ->setDeactivatedAt($now)
                            ->setUpdatedAt($now);

                        ++$deactivated;
                    }
                }
            }

            $lock->refresh();
            $this->em->flush();
            $this->em->getConnection()->commit();
            $transaction = false;

            $io->table(
                ['Novas', 'Atualizadas', 'Reativadas', 'Desativadas'],
                [[$created, $updated, $reactivated, $deactivated]],
            );

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            if ($transaction && $this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }

            if ($this->em->isOpen()) {
                $this->em->clear();
            }

            $io->error(sprintf('Importacao do parceiro %d nao concluida: %s. Nenhum lote pendente foi confirmado.', $id, $exception::class));

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function text(mixed $value, int $max = 255): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    private function severity(mixed $value): ?string
    {
        return match ((string) $value) {
            '0' => 'Normal',
            '1' => 'Leve',
            '2' => 'Moderado',
            '3' => 'Alto',
            '4' => 'Muito alto',
            '5' => 'Parado',
            default => null,
        };
    }

    private function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        if ($value <= 0 || !is_finite($value)) {
            return null;
        }

        if ($value > 100000000000) {
            $value /= 1000;
        }

        return (new \DateTimeImmutable('@' . (int) $value))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function position(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*$/', trim($value), $m)) {
            return null;
        }

        $lat = (float) $m[1];
        $lng = (float) $m[2];

        if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function firstPoint(mixed $line): ?array
    {
        if (!is_array($line) || !array_is_list($line) || !is_array($line[0] ?? null)) {
            return null;
        }

        $lat = $line[0]['y'] ?? null;
        $lng = $line[0]['x'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function fingerprint(array $data): string
    {
        $line = $data['line'] ?? null;

        if (!is_array($line) || !array_is_list($line) || count($line) < 2) {
            throw new \RuntimeException('Irregularidade sem geometria utilizavel.');
        }

        $geometry = [];

        foreach ($line as $point) {
            if (!is_array($point) || !is_numeric($point['x'] ?? null) || !is_numeric($point['y'] ?? null)) {
                throw new \RuntimeException('Coordenada invalida.');
            }

            $x = (float) $point['x'];
            $y = (float) $point['y'];

            if (!is_finite($x) || !is_finite($y) || abs($x) > 180 || abs($y) > 90) {
                throw new \RuntimeException('Coordenada fora dos limites.');
            }

            $pair = [number_format($x, 5, '.', ''), number_format($y, 5, '.', '')];

            if ($geometry === [] || $pair !== $geometry[count($geometry) - 1]) {
                $geometry[] = $pair;
            }
        }

        if (count($geometry) < 2) {
            throw new \RuntimeException('Geometria degenerada.');
        }

        $identity = [
            'type' => $this->text($data['type'] ?? null),
            'name' => $this->text($data['name'] ?? null),
            'from' => $this->text($data['fromName'] ?? null),
            'to' => $this->text($data['toName'] ?? null),
            'geometry' => $geometry,
        ];

        return 'rootv1:' . substr(hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), 0, 57);
    }
}
