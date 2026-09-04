<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:create-user-partner',
    description: 'Cria parceiros e/ou usuários com diferentes papéis (super-admin, admin, user).',
)]
class CreateUserPartnerCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface          $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'Nome do parceiro (opcional)')
            ->addOption('partner-code', null, InputOption::VALUE_OPTIONAL, 'Código do parceiro')
            ->addOption('users', null, InputOption::VALUE_OPTIONAL, 'Lista de emails separados por vírgula (ex: a@b.com,c@d.com)')
            ->addOption('role', null, InputOption::VALUE_OPTIONAL, 'Papel padrão para os usuários (super-admin, admin, user)', 'user')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Senha padrão para todos os usuários');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $io->title('Criar Parceiro e/ou Usuários');

        // ── Parceiro ────────────────────────────────────────────────────────────────
        $partner = null;
        $partnerName = $input->getOption('partner');

        if ($partnerName) {
            $partner = new Partner();
            $partner->setName($partnerName);
            $partner->setCode($input->getOption('partner-code') ?: strtoupper(substr($partnerName, 0, 10)));
            $partner->setActive(true);
            $this->em->persist($partner);
            $io->success("Parceiro '{$partnerName}' criado com sucesso.");
        } else {
            $createPartner = $io->confirm('Deseja criar um parceiro?', false);
            if ($createPartner) {
                $partnerName = $io->ask('Nome do parceiro', null, function ($v) {
                    if (empty(trim($v))) throw new \RuntimeException('Nome é obrigatório.');
                    return trim($v);
                });
                $partnerCode = $io->ask('Código do parceiro (opcional)');
                $partner = new Partner();
                $partner->setName($partnerName);
                $partner->setCode($partnerCode ?: strtoupper(substr($partnerName, 0, 10)));
                $partner->setActive(true);
                $this->em->persist($partner);
                $io->success("Parceiro '{$partnerName}' criado.");
            }
        }

        // ── Usuários ──────────────────────────────────────────────────────────────────
        $emails = [];
        $userEmailsOption = $input->getOption('users');

        if ($userEmailsOption) {
            $emails = array_map('trim', explode(',', $userEmailsOption));
        } else {
            $emailsInput = $io->ask('E-mails dos usuários (separados por vírgula)');
            if ($emailsInput) {
                $emails = array_map('trim', explode(',', $emailsInput));
            }
        }

        if (empty($emails)) {
            $io->warning('Nenhum usuário fornecido.');
            return Command::SUCCESS;
        }

        $roleMap = [
            'super-admin' => ['ROLE_SUPER_ADMIN'],
            'admin'       => ['ROLE_ADMIN'],
            'user'        => ['ROLE_USER'],
        ];

        $roleOption = $input->getOption('role') ?: 'user';
        if (!isset($roleMap[$roleOption])) {
            $io->error('Papel inválido. Use: super-admin, admin, user');
            return Command::FAILURE;
        }

        $defaultRole = $roleMap[$roleOption];
        $defaultPassword = $input->getOption('password');

        $createdUsers = 0;

        foreach ($emails as $email) {
            $email = trim($email);
            if (!$email) continue;

            // Valida e-mail
            $errors = $this->validator->validate($email, [new NotBlank(), new Email()]);
            if (count($errors) > 0) {
                $io->error("E-mail inválido: {$email}");
                continue;
            }

            // Verifica duplicidade
            $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing) {
                $io->warning("Usuário com e-mail {$email} já existe. Pulando.");
                continue;
            }

            // Senha
            $password = $defaultPassword;
            if (!$password) {
                $question = new Question("Senha para {$email} (oculta): ");
                $question->setHidden(true);
                $question->setHiddenFallback(false);
                $question->setValidator(function ($v) {
                    if (strlen((string) $v) < 8) throw new \RuntimeException('Senha deve ter pelo menos 8 caracteres.');
                    return $v;
                });
                $password = $helper->ask($input, $output, $question);
            }

            // Cria usuário (sem campo 'name')
            $user = new User();
            $user->setEmail($email);
            $user->setRoles($defaultRole);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->setPartner($partner);

            $this->em->persist($user);
            $createdUsers++;
        }

        $this->em->flush();

        $io->success([
            "{$createdUsers} usuário(s) criado(s) com sucesso!",
            $partner ? "Parceiro: {$partner->getName()}" : 'Sem parceiro associado.',
        ]);

        return Command::SUCCESS;
    }
}
