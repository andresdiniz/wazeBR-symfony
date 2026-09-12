<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Command\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:create:partner-user',
    description: 'Creates a new user for a partner',
    hidden: false,
)]
final class CreatePartnerUserCommand extends AbstractCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly PartnerRepository $partnerRepository,
        private readonly UserRepository $userRepository,
        private readonly EmailVerifier $emailVerifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner-name', null, InputOption::VALUE_REQUIRED, 'Partner name')
            ->addOption('partner-code', null, InputOption::VALUE_REQUIRED, 'Partner code')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'User email')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'User password')
            ->addOption('roles', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'User roles', ['ROLE_USER'])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $partnerName = $input->getOption('partner-name');
        $partnerCode = $input->getOption('partner-code');
        $email = $input->getOption('email');
        $password = $input->getOption('password');
        $roles = $input->getOption('roles');

        if (!$partnerName || !$partnerCode) {
            $io->error('Partner name and code are required.');
            return Command::FAILURE;
        }

        $partner = $this->partnerRepository->findOneBy(['code' => $partnerCode]);

        if (!$partner) {
            $io->warning("Partner with code '{$partnerCode}' not found. Creating new partner...");
            $partner = new Partner();
            $partner->setName($partnerName);
            $partner->setCode($partnerCode);
            $this->entityManager->persist($partner);
            $this->entityManager->flush();
        }

        if (!$email || !$password) {
            $io->error('Email and password are required.');
            return Command::FAILURE;
        }

        $existingUser = $this->userRepository->findOneBy(['email' => $email]);

        if ($existingUser) {
            $io->error("User with email '{$email}' already exists.");
            return Command::FAILURE;
        }

        $user = new \App\Entity\User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPartner($partner);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $violations = $this->validator->validate($user);

        if (count($violations) > 0) {
            $io->error('Validation errors:');
            foreach ($violations as $violation) {
                $io->writeln("- {$violation->getMessage()}");
            }
            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user);

        $io->success("User '{$email}' created successfully for partner '{$partner->getName()}'!");

        $io->definitionList(
            ['Partner ID' => $partner->getId()],
            ['Nome' => $partner->getName()],
            ['Cidade' => $partner->getCity()],
            ['Estado' => $partner->getState()],
            ['User ID' => $user->getId()],
            ['Email' => $user->getEmail()],
            ['Roles' => implode(', ', $user->getRoles())],
        );

        return Command::SUCCESS;
    }
}