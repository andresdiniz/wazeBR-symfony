<?php

namespace App\Command;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Cria um ou mais usuarios para um parceiro'
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partnerRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('partner-id', InputArgument::REQUIRED, 'ID do parceiro')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Quantidade de usuarios', '1')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'Papel (ADMIN, OPERADOR, etc)', 'ADMIN')
            ->addOption('email-prefix', null, InputOption::VALUE_REQUIRED, 'Prefixo do email', 'user')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Senha padrao', 'mudar123')
            ->addOption('name-prefix', null, InputOption::VALUE_REQUIRED, 'Prefixo do nome', 'Usuario');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $partnerId = (int) $input->getArgument('partner-id');
        $count = (int) $input->getOption('count');
        $role = $input->getOption('role');
        $emailPrefix = $input->getOption('email-prefix');
        $password = $input->getOption('password');
        $namePrefix = $input->getOption('name-prefix');

        $partner = $this->partnerRepository->find($partnerId);
        if (!$partner) {
            $output->writeln(sprintf('<error>Parceiro ID %d nao encontrado</error>', $partnerId));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Criando %d usuario(s) para: %s</info>', $count, $partner->getName()));

        for ($i = 1; $i <= $count; $i++) {
            $email = sprintf('%s%d@%s', $emailPrefix, $i, $partner->getCode() . '.local');
            $name = sprintf('%s %d', $namePrefix, $i);

            $user = new User();
            $user->setEmail($email);
            $user->setName($name);
            $user->setPartner($partner);
            $user->setRoles([$role]);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $user->setCreatedAt(new \DateTime());
            $user->setUpdatedAt(new \DateTime());

            $this->em->persist($user);
            $output->writeln(sprintf('  [+] %s (%s)', $email, $role));
        }

        $this->em->flush();
        $output->writeln(sprintf('<comment>Senha padrao: %s</comment>', $password));
        $output->writeln('<info>Usuarios criados com sucesso!</info>');

        return Command::SUCCESS;
    }
}
