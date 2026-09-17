<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin_user_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserController extends AbstractController
{
    private const AVAILABLE_ROLES = [
        User::ROLE_ADMIN,
        User::ROLE_PARTNER_ADMIN,
        User::ROLE_OPERATOR,
        User::ROLE_VIEWER,
    ];

    private const ROLES_REQUIRING_PARTNER = [
        User::ROLE_PARTNER_ADMIN,
        User::ROLE_OPERATOR,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly PartnerRepository $partners,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $this->users->findBy([], ['email' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleCreate($request);
        }

        return $this->render('admin/user/new.html.twig', [
            'partners'            => $this->partners->findBy([], ['name' => 'ASC']),
            'availableRoles'      => self::AVAILABLE_ROLES,
            'rolesRequirePartner' => self::ROLES_REQUIRING_PARTNER,
        ]);
    }

    private function handleCreate(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(
            'admin_user_new',
            (string) $request->request->get('_token'),
        )) {
            $this->addFlash('error', 'Token de segurança inválido.');

            return $this->redirectToRoute('admin_user_new');
        }

        $email    = trim((string) $request->request->get('email', ''));
        $name     = trim((string) $request->request->get('name', ''));
        $phone    = trim((string) $request->request->get('phone', ''));
        $password = (string) $request->request->get('password', '');
        $role     = (string) $request->request->get('role', User::ROLE_VIEWER);
        $partner  = $request->request->get('partnerId');

        $errors = [];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail inválido.';
        } elseif ($this->users->findOneBy(['email' => $email]) !== null) {
            $errors[] = 'Este e-mail já está cadastrado.';
        }

        if (mb_strlen($password) < 8) {
            $errors[] = 'A senha deve ter pelo menos 8 caracteres.';
        }

        if (!in_array($role, self::AVAILABLE_ROLES, true)) {
            $errors[] = 'Papel de usuário inválido.';
        }

        $partnerEntity = null;
        if ($partner !== null && $partner !== '') {
            $partnerEntity = $this->partners->find((int) $partner);

            if ($partnerEntity === null) {
                $errors[] = 'Parceiro informado não foi encontrado.';
            }
        }

        if (
            in_array($role, self::ROLES_REQUIRING_PARTNER, true)
            && $partnerEntity === null
        ) {
            $errors[] = sprintf('O papel %s exige um parceiro associado.', $role);
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('admin_user_new');
        }

        $user = new User();

        if ($partnerEntity !== null) {
            // Precisa vir antes de setRoles por causa das regras do domínio.
            $user->setPartner($partnerEntity);
        }

        $user->setEmail($email);
        $user->setName($name);
        $user->setPhone($phone !== '' ? $phone : null);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        try {
            $user->setRoles([$role]);
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_user_new');
        }

        $this->em->persist($user);
        $this->em->flush();

        $this->addFlash('success', sprintf('Usuário "%s" criado com sucesso.', $email));

        return $this->redirectToRoute('admin_user_index');
    }
}
