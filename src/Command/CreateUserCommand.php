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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:create:user',
    description: 'Cria um usuário. Pode ou não estar vinculado a um partner existente.',
)]
final class CreateUserCommand extends Command
{
    private const VALID_ROLES = [
        User::ROLE_ADMIN,
        User::ROLE_PARTNER_ADMIN,
        User::ROLE_OPERATOR,
        User::ROLE_VIEWER,
    ];

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
            ->addOption('email',    null, InputOption::VALUE_REQUIRED, 'E-mail do usuário (obrigatório)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Senha em texto puro (obrigatória)')
            ->addOption('name',     null, InputOption::VALUE_REQUIRED, 'Nome completo (opcional)')
            ->addOption('phone',    null, InputOption::VALUE_REQUIRED, 'Telefone (opcional)')
            ->addOption('roles',    null, InputOption::VALUE_REQUIRED, 'Roles separadas por vírgula', User::ROLE_VIEWER)
            ->addOption('partner-id',   null, InputOption::VALUE_REQUIRED, 'ID do partner a vincular (opcional)')
            ->addOption('partner-code', null, InputOption::VALUE_REQUIRED, 'Code do partner a vincular (opcional)')
            ->addOption('list-partners', null, InputOption::VALUE_NONE, 'Lista partners existentes e sai')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --list-partners: mostra os partners e encerra
        if ($input->getOption('list-partners')) {
            return $this->listPartners($io);
        }

        $email    = trim((string) $input->getOption('email'));
        $password = (string) $input->getOption('password');
        $name     = $input->getOption('name');
        $phone    = $input->getOption('phone');

        if ($email === '') {
            $io->error('O parâmetro --email é obrigatório.');
            return Command::FAILURE;
        }

        if ($password === '') {
            $io->error('O parâmetro --password é obrigatório.');
            return Command::FAILURE;
        }

        // Roles
        $rolesRaw = (string) $input->getOption('roles');
        $roles = array_values(array_unique(array_filter(array_map(
            static fn (string $r): string => strtoupper(trim($r)),
            explode(',', $rolesRaw),
        ))));

        if ($roles === []) {
            $roles = [User::ROLE_VIEWER];
        }

        $invalid = array_diff($roles, self::VALID_ROLES);
        if ($invalid !== []) {
            $io->error(sprintf(
                'Roles inválidas: %s. Permitidas: %s.',
                implode(', ', $invalid),
                implode(', ', self::VALID_ROLES),
            ));
            return Command::FAILURE;
        }

        // Verifica duplicidade
        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            $io->error(sprintf('Já existe um usuário com o e-mail "%s".', $email));
            return Command::FAILURE;
        }

        // Resolve partner (opcional)
        $partner = null;
        $partnerId   = $input->getOption('partner-id');
        $partnerCode = $input->getOption('partner-code');

        if ($partnerId !== null && $partnerCode !== null) {
            $io->error('Use --partner-id OU --partner-code, não os dois.');
            return Command::FAILURE;
        }

        if ($partnerId !== null) {
            $partner = $this->partnerRepository->find((int) $partnerId);
            if ($partner === null) {
                $io->error(sprintf('Partner com id=%s não encontrado.', $partnerId));
                return Command::FAILURE;
            }
        } elseif ($partnerCode !== null) {
            $partner = $this->partnerRepository->findOneBy(['code' => $partnerCode]);
            if ($partner === null) {
                $io->error(sprintf('Partner com code="%s" não encontrado.', $partnerCode));
                return Command::FAILURE;
            }
        }

        // Pré-check de negócio: ROLE_PARTNER_ADMIN / ROLE_OPERATOR exigem partner
        $rolesRequiringPartner = [User::ROLE_PARTNER_ADMIN, User::ROLE_OPERATOR];
        if (array_intersect($rolesRequiringPartner, $roles) !== [] && $partner === null) {
            $io->error(sprintf(
                'As roles %s exigem um partner vinculado. Informe --partner-id ou --partner-code.',
                implode(' / ', $rolesRequiringPartner),
            ));
            return Command::FAILURE;
        }

        // ─────────────────────────────────────────────────────────────
        // MONTAGEM DO USUÁRIO
        // A ORDEM IMPORTA: setPartner() antes de setRoles(),
        // porque setRoles() valida que as roles que exigem partner
        // só podem ser atribuídas se o partner já estiver presente.
        // ─────────────────────────────────────────────────────────────
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        // 1) partner primeiro (se houver)
        if ($partner !== null) {
            $user->setPartner($partner);
        }

        // 2) opcionais
        if ($name !== null)  $user->setName((string) $name);
        if ($phone !== null) $user->setPhone((string) $phone);

        // 3) roles por último (já com partner setado)
        $user->setRoles($roles);

        // Valida com os asserts da entidade
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $io->error('Erros de validação:');
            foreach ($errors as $error) {
                $io->writeln(sprintf('  - %s: %s', $error->getPropertyPath(), $error->getMessage()));
            }
            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Usuário criado: %s', $user->getEmail()));
        $io->table(
            ['Campo', 'Valor'],
            [
                ['ID',        $user->getId()],
                ['E-mail',    $user->getEmail()],
                ['Nome',      $user->getName() ?? '—'],
                ['Telefone',  $user->getPhone() ?? '—'],
                ['Roles',     implode(', ', $user->getRoles())],
                ['Partner',   $partner ? sprintf('%s (%s)', $partner->getName(), $partner->getCode()) : 'Nenhum (global)'],
            ],
        );

        return Command::SUCCESS;
    }

    private function listPartners(SymfonyStyle $io): int
    {
        $partners = $this->partnerRepository->findBy([], ['id' => 'ASC']);

        if ($partners === []) {
            $io->warning('Nenhum partner cadastrado.');
            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Code', 'Nome', 'Cidade', 'UF'],
            array_map(static fn (Partner $p): array => [
                $p->getId(),
                $p->getCode(),
                $p->getName(),
                $p->getCity() ?? '—',
                $p->getState() ?? '—',
            ], $partners),
        );

        return Command::SUCCESS;
    }
}
