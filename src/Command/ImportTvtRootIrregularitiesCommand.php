<?php

declare(strict_types=1);
namespace App\Command;

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

#[AsCommand(name: 'app:fetch:waze:tvt-irregularities', description: 'Importa irregularities da raiz do feed TVT, sem criar rotas fictícias.')]
final class ImportTvtRootIrregularitiesCommand extends Command
{
    public function __construct(private readonly PartnerRepository $partners, private readonly PartnerApiLinkRepository $links, private readonly EntityManagerInterface $em, private readonly HttpClientInterface $http, private readonly LockFactory $locks) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('partner', null, InputOption::VALUE_REQUIRED, 'ID interno obrigatório do parceiro.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Arquivo JSON local; modo de importação sem desativação de registros ausentes.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Valida e conta sem gravar no banco.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = filter_var($input->getOption('partner'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null || !($partner = $this->partners->find($id))) { $io->error('Informe --partner com um ID interno existente.'); return Command::INVALID; }
        if (!$partner->isActive()) { $io->error('O parceiro está inativo.'); return Command::INVALID; }
        $lock = $this->locks->createLock('waze:tvt:partner:' . $id, 600.0);
        if (!$lock->acquire()) { $io->warning('Outra coleta TVT está executando para este parceiro.'); return Command::FAILURE; }
        $transaction = false;
        try {
            $payloads = [];
            $file = $input->getOption('file');
            if ($file !== null) {
                if (!is_file($file) || !is_readable($file) || filesize($file) > 20000000) throw new \RuntimeException('Arquivo inválido, inacessível ou maior que 20 MB.');
                $payloads[] = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } else {
                foreach ($this->links->findAllByType('TVT') as $link) {
                    if ($link->getPartner()?->getId() !== $partner->getId()) continue;
                    $url = (string) $link->getUrl();
                    if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) throw new \RuntimeException('Protocolo do link TVT inválido.');
                    $payloads[] = $this->http->request('GET', $url, ['timeout' => 30, 'max_duration' => 60, 'headers' => ['Accept' => 'application/json']])->toArray();
                    $lock->refresh();
                }
            }
            if ($payloads === []) throw new \RuntimeException('Nenhum feed TVT encontrado para o parceiro.');
            $items = [];
            $authoritative = $file === null;
            $received = 0;
            foreach ($payloads as $payload) {
                if (!is_array($payload)) throw new \RuntimeException('A raiz do JSON deve ser um objeto.');
                if (!array_key_exists('irregularities', $payload)) { $authoritative = false; continue; }
                $list = $payload['irregularities'];
                if (!is_array($list) || !array_is_list($list)) throw new \RuntimeException('irregularities deve ser uma lista; nenhuma alteração foi aplicada.');
                foreach ($list as $data) {
                    if (!is_array($data)) throw new \RuntimeException('Irregularidade inválida; nenhuma alteração foi aplicada.');
                    ++$received;
                    $items[$this->fingerprint($data)] = $data;
                }
            }
            $io->table(['Parceiro', 'Recebidas', 'Identidades distintas', 'Pode desativar ausentes'], [[$id, $received, count($items), $authoritative ? 'sim' : 'não']]);
            if ($input->getOption('dry-run')) { $io->success('Simulação concluída; nenhum registro foi gravado ou desativado.'); return Command::SUCCESS; }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $repository = $this->em->getRepository(WazeTvtIrregularity::class);
            $existing = $repository->createQueryBuilder('i')->where('i.partner = :partner')->andWhere('i.route IS NULL')->andWhere('i.subRoute IS NULL')->andWhere('i.contentHash LIKE :prefix')->setParameter('partner', $partner)->setParameter('prefix', 'rootv1:%')->getQuery()->getResult();
            $byHash = [];
            foreach ($existing as $entity) $byHash[$entity->getContentHash()] = $entity;
            $this->em->getConnection()->beginTransaction(); $transaction = true;
            $created = $updated = $reactivated = $deactivated = 0;
            foreach ($items as $hash => $data) {
                $entity = $byHash[$hash] ?? null;
                if ($entity === null) {
                    $entity = (new WazeTvtIrregularity())->setPartner($partner)->setRoute(null)->setSubRoute(null)->setWazeRouteId(null)->setWazeSubRouteId(null)->setContentHash($hash)->setRecordedAt($now);
                    $this->em->persist($entity); ++$created;
                } else { ++$updated; if (!$entity->isActive()) ++$reactivated; }
                $entity->setType($this->text($data['type'] ?? null))->setSubtype($this->text($data['subtype'] ?? null))
                    ->setDescription($this->text($data['description'] ?? null))->setStreet($this->text($data['street'] ?? null))
                    ->setCity($this->text($data['city'] ?? null))->setState($this->text($data['state'] ?? null, 2))
                    ->setPayload($data)->setIsActive(true)->setLastSeenAt($now)->setUpdatedAt($now);
            }
            if ($authoritative) foreach ($byHash as $hash => $entity) {
                if (!isset($items[$hash]) && $entity->isActive()) { $entity->setIsActive(false)->setDeactivatedAt($now)->setUpdatedAt($now); ++$deactivated; }
            }
            $lock->refresh();
            $this->em->flush(); $this->em->getConnection()->commit(); $transaction = false;
            $io->table(['Novas', 'Atualizadas', 'Reativadas', 'Desativadas'], [[$created, $updated, $reactivated, $deactivated]]);
            $io->success('Irregularidades da raiz importadas. Geometria, tempos, jamLevel e ID da fonte preservados no payload.');
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            if ($transaction && $this->em->getConnection()->isTransactionActive()) $this->em->getConnection()->rollBack();
            $io->error('Importação não concluída: ' . $exception::class . '. Nenhum lote pendente foi confirmado. Confira schema, conectividade e JSON; simule antes de executar.');
            return Command::FAILURE;
        } finally { $lock->release(); }
    }
    private function text(mixed $value, int $max = 255): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
    private function fingerprint(array $data): string
    {
        $line = $data['line'] ?? null;
        if (!is_array($line) || !array_is_list($line) || count($line) < 2) throw new \RuntimeException('Irregularidade sem geometria utilizável.');
        $geometry = [];
        foreach ($line as $point) {
            if (!is_array($point) || !is_numeric($point['x'] ?? null) || !is_numeric($point['y'] ?? null)) throw new \RuntimeException('Coordenada inválida.');
            $x = (float) $point['x']; $y = (float) $point['y'];
            if (!is_finite($x) || !is_finite($y) || abs($x) > 180 || abs($y) > 90) throw new \RuntimeException('Coordenada fora dos limites.');
            $pair = [number_format($x, 5, '.', ''), number_format($y, 5, '.', '')];
            if ($geometry === [] || $pair !== $geometry[count($geometry) - 1]) $geometry[] = $pair;
        }
        if (count($geometry) < 2) throw new \RuntimeException('Geometria degenerada.');
        $identity = ['type' => $this->text($data['type'] ?? null), 'name' => $this->text($data['name'] ?? null), 'from' => $this->text($data['fromName'] ?? null), 'to' => $this->text($data['toName'] ?? null), 'geometry' => $geometry];
        return 'rootv1:' . substr(hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)), 0, 57);
    }
}
