<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
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
    name: 'app:create-super-admin',
    description: 'Cria um usuário ROLE_SUPER_ADMIN na plataforma.',
)]
class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserRepository              $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface          $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name',     null, InputOption::VALUE_OPTIONAL, 'Nome completo')
            ->addOption('email',    null, InputOption::VALUE_OPTIONAL, 'E-mail de acesso')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Senha (mínimo 8 caracteres)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper('question');

        $io->title('Criar Super Admin — TrafIK Platform');

        // ── Nome ─────────────────────────────────────────────────────────────────
        $name = $input->getOption('name');
        if (!$name) {
            $question = new Question('<info>Nome completo</info>: ');
            $question->setValidator(function (?string $value): string {
                if (empty(trim((string) $value))) {
                    throw new \RuntimeException('O nome não pode ser vazio.');
                }
                return trim($value);
            });
            $name = $helper->ask($input, $output, $question);
        }

        // ── E-mail ─────────────────────────────────────────────────────────────
        $email = $input->getOption('email');
        if (!$email) {
            $question = new Question('<info>E-mail</info>: ');
            $question->setValidator(function (?string $value): string {
                $value = trim((string) $value);
                $errors = $this->validator->validate($value, [new NotBlank(), new Email()]);
                if (count($errors) > 0) {
                    throw new \RuntimeException('E-mail inválido: ' . $errors->get(0)->getMessage());
                }
                return $value;
            });
            $email = $helper->ask($input, $output, $question);
        }

        // Verifica duplicidade
        if ($this->userRepository->findByEmail($email)) {
            $io->error('Já existe um usuário com o e-mail "' . $email . '".');
            return Command::FAILURE;
        }

        // ── Senha ─────────────────────────────────────────────────────────────
        $plainPassword = $input->getOption('password');
        if (!$plainPassword) {
            $question = new Question('<info>Senha</info> (oculta): ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $question->setValidator(function (?string $value): string {
                $errors = $this->validator->validate((string) $value, [
                    new NotBlank(),
                    new Length(min: 8, max: 64),
                ]);
                if (count($errors) > 0) {
                    throw new \RuntimeException($errors->get(0)->getMessage());
                }
                return (string) $value;
            });
            $plainPassword = $helper->ask($input, $output, $question);

            // Confirmação
            $confirm = new Question('<info>Confirme a senha</info> (oculta): ');
            $confirm->setHidden(true);
            $confirm->setHiddenFallback(false);
            $confirmed = $helper->ask($input, $output, $confirm);

            if ($plainPassword !== $confirmed) {
                $io->error('As senhas não coincidem.');
                return Command::FAILURE;
            }
        }

        // ── Cria o usuário ────────────────────────────────────────────────────────
        $user = new User();
        $user->setName($name);
        $user->setEmail($email);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $user->setIsActive(true);
        // partner = null (super admin não pertence a nenhum parceiro)

        $this->em->persist($user);
        $this->em->flush();

        $io->success([
            'Super Admin criado com sucesso!',
            'Nome:   ' . $user->getName(),
            'E-mail: ' . $user->getEmail(),
            'ID:     ' . $user->getId(),
        ]);

        return Command::SUCCESS;
    }
}
