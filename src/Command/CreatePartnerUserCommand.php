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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Cria um Partner, um User, ou ambos em uma única execução.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * MODOS DE USO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Interativo (sem opções):
 *   php bin/console app:create:partner-user
 *   → wizard pergunta o que criar e pede os dados um a um.
 *
 * Só Partner:
 *   php bin/console app:create:partner-user \
 *     --partner-name="Prefeitura de BH" \
 *     --partner-code="pbh" \
 *     --no-user
 *
 * Só User (vinculado a partner existente):
 *   php bin/console app:create:partner-user \
 *     --email=ops@pbh.gov.br \
 *     --password=senha123 \
 *     --roles=ROLE_ADMIN \
 *     --partner-id=7 \
 *     --no-partner
 *
 * User global admin (sem partner):
 *   php bin/console app:create:partner-user \
 *     --email=admin@wazebr.com \
 *     --password=senha123 \
 *     --roles=ROLE_SUPER_ADMIN \
 *     --no-partner
 *
 * Partner + User juntos:
 *   php bin/console app:create:partner-user \
 *     --partner-name="Belo Horizonte" \
 *     --partner-code="bh" \
 *     --email=admin@bh.mg.gov.br \
 *     --password=secret \
 *     --roles=ROLE_ADMIN
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ROLES DISPONÍVEIS
 * ─────────────────────────────────────────────────────────────────────────────
 *   ROLE_SUPER_ADMIN  → admin global, sem partner obrigatório
 *   ROLE_ADMIN        → admin de partner (partner obrigatório)
 *   ROLE_OPERATOR     → operador de partner (partner obrigatório)
 *   ROLE_USER         → acesso básico
 */
#[AsCommand(
    name: 'app:create:partner-user',
    description: 'Cria um Partner, um User, ou ambos de forma interativa ou via opções.',
)]
class CreatePartnerUserCommand extends Command
{
    /** Roles que exigem vínculo com partner. */
    private const PARTNER_REQUIRED_ROLES = ['ROLE_ADMIN', 'ROLE_OPERATOR'];

    /** Todas as roles disponíveis para escolha interativa. */
    private const AVAILABLE_ROLES = [
        'ROLE_SUPER_ADMIN',
        'ROLE_ADMIN',
        'ROLE_OPERATOR',
        'ROLE_USER',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SluggerInterface $slugger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // ── Partner ──────────────────────────────────────────────────────
            ->addOption('partner-name', null, InputOption::VALUE_REQUIRED, 'Nome do partner')
            ->addOption('partner-code', null, InputOption::VALUE_REQUIRED, 'Código curto do partner (ex: pbh)')
            ->addOption('partner-id', null, InputOption::VALUE_REQUIRED, 'ID de um partner existente para vincular ao user')
            ->addOption('no-partner', null, InputOption::VALUE_NONE, 'Não criar/vincular partner — cria apenas o user')

            // ── User ─────────────────────────────────────────────────────────
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail do usuário')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Senha em texto puro (será hasheada)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nome do usuário')
            ->addOption('roles', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Roles do usuário (pode repetir: --roles=ROLE_ADMIN --roles=ROLE_USER)', ['ROLE_USER'])
            ->addOption('no-user', null, InputOption::VALUE_NONE, 'Não criar usuário — cria apenas o partner');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Criar Partner / User');

        $noPartner = (bool) $input->getOption('no-partner');
        $noUser = (bool) $input->getOption('no-user');

        // ── Detectar modo ────────────────────────────────────────────────────
        $isInteractive = !$input->isInteractive() === false
            && $input->getOption('partner-name') === null
            && $input->getOption('email') === null
            && !$noPartner
            && !$noUser;

        if ($noPartner && $noUser) {
            $io->error('--no-partner e --no-user usados juntos: nada a criar.');
            return Command::FAILURE;
        }

        // ── Modo interativo: escolher o que criar ────────────────────────────
        if ($isInteractive) {
            $what = $io->choice(
                'O que deseja criar?',
                [
                    'partner_and_user' => 'Partner + User',
                    'partner_only'     => 'Somente Partner',
                    'user_only'        => 'Somente User',
                ],
                'partner_and_user',
            );

            $noPartner = $what === 'user_only';
            $noUser = $what === 'partner_only';
        }

        $partner = null;
        $user = null;

        // ════════════════════════════════════════════════════════════════════
        // A. CRIAR / RESOLVER PARTNER
        // ════════════════════════════════════════════════════════════════════
        if (!$noPartner) {
            $partner = $this->resolvePartner($input, $io);

            if ($partner === null) {
                return Command::FAILURE;
            }
        }

        // ════════════════════════════════════════════════════════════════════
        // B. VINCULAR PARTNER EXISTENTE (--partner-id sem criar um novo)
        // ════════════════════════════════════════════════════════════════════
        if ($noPartner && !$noUser && $input->getOption('partner-id') !== null) {
            $partner = $this->findPartnerById((int) $input->getOption('partner-id'), $io);

            if ($partner === null) {
                return Command::FAILURE;
            }
        }

        // ════════════════════════════════════════════════════════════════════
        // C. CRIAR USER
        // ════════════════════════════════════════════════════════════════════
        if (!$noUser) {
            $user = $this->buildUser($input, $io, $partner);

            if ($user === null) {
                return Command::FAILURE;
            }
        }

        // ════════════════════════════════════════════════════════════════════
        // D. PERSISTIR
        // ════════════════════════════════════════════════════════════════════
        if ($partner !== null && $partner->getId() === null) {
            $this->em->persist($partner);
        }

        if ($user !== null) {
            $this->em->persist($user);
        }

        $this->em->flush();

        // ── Sumário ──────────────────────────────────────────────────────────
        $io->success('Criação concluída com sucesso!');

        if ($partner !== null) {
            $io->definitionList(
                ['Partner ID']    => $partner->getId(),
                ['Nome']          => $partner->getName(),
                ['Código']        => $partner->getCode(),
                ['Slug']          => $partner->getSlug(),
            );
        }

        if ($user !== null) {
            $io->definitionList(
                ['User ID']  => $user->getId(),
                ['E-mail']   => $user->getEmail(),
                ['Nome']     => $user->getName(),
                ['Roles']    => implode(', ', $user->getRoles()),
                ['Partner']  => $user->getPartner()?->getName() ?? '— (admin global)',
            );
        }

        return Command::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PARTNER
    // ═══════════════════════════════════════════════════════════════════════

    private function resolvePartner(InputInterface $input, SymfonyStyle $io): ?Partner
    {
        // ── Coletar nome ────────────────────────────────────────────────────
        $name = $input->getOption('partner-name');

        if ($name === null) {
            $name = $io->ask('Nome do partner', null, static function (?string $v): string {
                if (empty(trim((string) $v))) {
                    throw new \RuntimeException('O nome não pode ser vazio.');
                }
                return trim($v);
            });
        }

        // ── Coletar código ──────────────────────────────────────────────────
        $code = $input->getOption('partner-code');

        if ($code === null) {
            $suggestedCode = strtolower(preg_replace('/\s+/', '_', (string) $name));
            $code = $io->ask(
                sprintf('Código curto do partner (sugerido: "%s")', $suggestedCode),
                $suggestedCode,
                static function (?string $v): string {
                    $v = strtolower(trim((string) $v));
                    if (!preg_match('/^[a-z0-9_\-]+$/', $v)) {
                        throw new \RuntimeException('O código deve conter apenas letras minúsculas, números, _ ou -.');
                    }
                    return $v;
                },
            );
        }

        $code = strtolower(trim((string) $code));

        // ── Verificar duplicidade de código ─────────────────────────────────
        $existing = $this->em->getRepository(Partner::class)->findOneBy(['code' => $code]);

        if ($existing !== null) {
            $io->error(sprintf('Já existe um partner com o código "%s" (ID %d — %s).', $code, $existing->getId(), $existing->getName()));
            return null;
        }

        // ── Montar slug ─────────────────────────────────────────────────────
        $slug = strtolower($this->slugger->slug((string) $name)->toString());

        // ── Criar entidade ──────────────────────────────────────────────────
        $partner = new Partner();
        $partner->setName((string) $name);
        $partner->setCode($code);
        $partner->setSlug($slug);

        $io->writeln(sprintf('  → Partner "<info>%s</info>" (code: %s, slug: %s) pronto para salvar.', $name, $code, $slug));

        return $partner;
    }

    private function findPartnerById(int $id, SymfonyStyle $io): ?Partner
    {
        $partner = $this->em->getRepository(Partner::class)->find($id);

        if ($partner === null) {
            $io->error(sprintf('Partner com ID %d não encontrado.', $id));
        }

        return $partner;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // USER
    // ═══════════════════════════════════════════════════════════════════════

    private function buildUser(InputInterface $input, SymfonyStyle $io, ?Partner $partner): ?User
    {
        // ── E-mail ──────────────────────────────────────────────────────────
        $email = $input->getOption('email');

        if ($email === null) {
            $email = $io->ask('E-mail do usuário', null, static function (?string $v): string {
                $v = trim((string) $v);
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('E-mail inválido.');
                }
                return $v;
            });
        }

        $email = trim((string) $email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('E-mail inválido: "%s".', $email));
            return null;
        }

        // ── Verificar duplicidade de e-mail ─────────────────────────────────
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($existing !== null) {
            $io->error(sprintf('Já existe um usuário com o e-mail "%s" (ID %d).', $email, $existing->getId()));
            return null;
        }

        // ── Nome ────────────────────────────────────────────────────────────
        $name = $input->getOption('name');

        if ($name === null) {
            $name = $io->ask('Nome do usuário (opcional)', null);
        }

        // ── Roles ───────────────────────────────────────────────────────────
        /** @var list<string> $roles */
        $roles = (array) $input->getOption('roles');

        // Modo interativo: perguntar roles se ainda é o default
        if ($roles === ['ROLE_USER'] && $input->isInteractive()) {
            $roles = $io->choice(
                'Role(s) do usuário',
                self::AVAILABLE_ROLES,
                'ROLE_USER',
            );
            $roles = is_array($roles) ? $roles : [$roles];
        }

        $roles = array_unique(array_map('strtoupper', $roles));

        // ── Validar vínculo de partner por role ─────────────────────────────
        $requiresPartner = !empty(array_intersect($roles, self::PARTNER_REQUIRED_ROLES));

        if ($requiresPartner && $partner === null) {
            // Modo interativo: oferecer busca de partner existente
            if ($input->isInteractive()) {
                $io->warning(sprintf(
                    'A(s) role(s) %s exigem um partner vinculado.',
                    implode(', ', array_intersect($roles, self::PARTNER_REQUIRED_ROLES)),
                ));

                $partnerId = $io->ask(
                    'Informe o ID de um partner existente (ou deixe em branco para abortar)',
                    null,
                );

                if ($partnerId === null) {
                    $io->error('User com role de partner sem partner vinculado — operação cancelada.');
                    return null;
                }

                $partner = $this->findPartnerById((int) $partnerId, $io);

                if ($partner === null) {
                    return null;
                }
            } else {
                $io->error(sprintf(
                    'A(s) role(s) %s exigem um partner. Use --partner-id=ID ou crie um partner junto.',
                    implode(', ', array_intersect($roles, self::PARTNER_REQUIRED_ROLES)),
                ));
                return null;
            }
        }

        // ── Senha ───────────────────────────────────────────────────────────
        $plainPassword = $input->getOption('password');

        if ($plainPassword === null) {
            $plainPassword = $io->askHidden('Senha (mínimo 8 caracteres)', static function (?string $v): string {
                if (strlen((string) $v) < 8) {
                    throw new \RuntimeException('A senha precisa ter no mínimo 8 caracteres.');
                }
                return (string) $v;
            });
        }

        if (strlen((string) $plainPassword) < 8) {
            $io->error('A senha precisa ter no mínimo 8 caracteres.');
            return null;
        }

        // ── Montar entidade ─────────────────────────────────────────────────
        $user = new User();
        $user->setEmail($email);
        $user->setName($name !== null ? trim((string) $name) : null);
        $user->setRoles($roles);
        $user->setPartner($partner);

        $hashed = $this->passwordHasher->hashPassword($user, (string) $plainPassword);
        $user->setPassword($hashed);

        $io->writeln(sprintf(
            '  → User "<info>%s</info>" (roles: %s, partner: %s) pronto para salvar.',
            $email,
            implode(', ', $roles),
            $partner?->getName() ?? 'nenhum (admin global)',
        ));

        return $user;
    }
}
