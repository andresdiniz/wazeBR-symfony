<?php

namespace App\Command;

use App\Entity\Partner;
use App\Entity\WazeFeed;
use App\Repository\WazeFeedRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-waze-feed',
    description: 'Cria ou atualiza um WazeFeed vinculado a um Partner'
)]
class CreateWazeFeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WazeFeedRepository $feedRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner',    null, InputOption::VALUE_REQUIRED, 'ID do Partner')
            ->addOption('type',       null, InputOption::VALUE_REQUIRED, 'EVENTS ou TVT')
            ->addOption('uuid',       null, InputOption::VALUE_REQUIRED, 'Feed UUID (do Waze)')
            ->addOption('url',        null, InputOption::VALUE_REQUIRED, 'Endpoint URL completo')
            ->addOption('label',      null, InputOption::VALUE_OPTIONAL, 'Rótulo administrativo')
            ->addOption('ext-partner',null, InputOption::VALUE_OPTIONAL, 'External Partner ID (numérico do Waze)')
            ->addOption('ext-route',  null, InputOption::VALUE_OPTIONAL, 'External Route ID (apenas para TVT)')
            ->addOption('inactive',   null, InputOption::VALUE_NONE,     'Criar como inativo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Validações básicas
        $partnerId = $input->getOption('partner');
        $feedType  = strtoupper((string)$input->getOption('type'));
        $feedUuid  = $input->getOption('uuid');
        $url       = $input->getOption('url');

        if (!$partnerId || !$feedType || !$feedUuid || !$url) {
            $io->error('--partner, --type, --uuid e --url são obrigatórios.');
            return Command::FAILURE;
        }

        if (!in_array($feedType, ['EVENTS', 'TVT'], true)) {
            $io->error('--type deve ser EVENTS ou TVT.');
            return Command::FAILURE;
        }

        $partner = $this->em->find(Partner::class, (int)$partnerId);
        if (!$partner) {
            $io->error(sprintf('Partner #%d não encontrado.', $partnerId));
            return Command::FAILURE;
        }

        $externalRouteId = $input->getOption('ext-route');

        // Verificar se já existe
        $existing = $this->feedRepository->findOneByUniqueConstraint(
            (int)$partnerId,
            $feedType,
            $feedUuid,
            $externalRouteId
        );

        if ($existing) {
            $io->note(sprintf('Feed #%d já existe. Atualizando URL e label.', $existing->getId()));
            $existing->setEndpointUrl($url);
            $existing->setLabel($input->getOption('label'));
            $existing->setIsActive(!$input->getOption('inactive'));
            $this->em->flush();
            $io->success(sprintf('Feed #%d atualizado.', $existing->getId()));
            return Command::SUCCESS;
        }

        $feed = new WazeFeed();
        $feed->setPartner($partner);
        $feed->setFeedType($feedType);
        $feed->setFeedUuid($feedUuid);
        $feed->setEndpointUrl($url);
        $feed->setLabel($input->getOption('label'));
        $feed->setExternalPartnerId($input->getOption('ext-partner'));
        $feed->setExternalRouteId($externalRouteId);
        $feed->setIsActive(!$input->getOption('inactive'));

        $this->em->persist($feed);
        $this->em->flush();

        $io->success(sprintf(
            'WazeFeed #%d criado: [%s] %s → Partner #%d (%s)',
            $feed->getId(),
            $feedType,
            $feedUuid,
            $partner->getId(),
            $feed->getLabel() ?? '-'
        ));

        return Command::SUCCESS;
    }
}
