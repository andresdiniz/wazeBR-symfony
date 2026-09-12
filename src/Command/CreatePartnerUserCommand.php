<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:create:partner-user',
    description: 'Creates a new user for a partner',
)]
final class CreatePartnerUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PartnerRepository $partnerRepository,
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner-name', null, InputOption::VALUE_REQUIRED, 'Partner name', null)
            ->addOption('partner-code', null, InputOption::VALUE_REQUIRED, 'Partner code', null)
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'User email', null)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'User password', null)
            ->addOption('roles', null, InputOption::VALUE_REQUIRED, 'User roles (comma-separated)', 'ROLE_ADMIN')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);

        $partnerName = $input->getOption('partner-name');
        $partnerCode = $input->getOption('partner-code');
        $email = $input->getOption('email');
        $password = $input->getOption('password');
        $roles = array_map('trim', explode(',', $input->getOption('roles')));

        // Create partner if code doesn't exist
        $partner = $this->partnerRepository->findOneBy(['code' => $partnerCode]);
        if (!$partner) {
            $partner = new Partner();
            $partner->setName($partnerName);
            $partner->setCode($partnerCode);
            $this->entityManager->persist($partner);
            $this->entityManager->flush();
            $io->success(sprintf('Created partner: %s (%s)', $partnerName, $partnerCode));
        } else {
            $io->warning(sprintf('Partner already exists: %s (%s)', $partner->getName(), $partnerCode));
        }

        // Check if user already exists
        $existingUser = $this->userRepository->findOneBy(['email' => $email]);
        if ($existingUser) {
            $io->error(sprintf('User already exists: %s', $email));
            return Command::FAILURE;
        }

        // Create user
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles($roles);
        $user->setPartner($partner);
        $user->setIsVerified(true);

        // Validate user
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $io->error('Validation errors: ' . (string) $errors);
            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Created user: %s with roles: %s', $email, implode(', ', $roles)));

        return Command::SUCCESS;
    }
}
