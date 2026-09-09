<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin/users', name: 'admin_user_')]
#[IsGranted('ROLE_ACCOUNT_ADMIN')]
class UserAdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $users = $this->userRepo->findAll();

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();

        return $this->handleForm($request, $user, 'Criar Usuário');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $user = $this->userRepo->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Usuário não encontrado.');
        }

        return $this->handleForm($request, $user, 'Editar Usuário');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $user = $this->userRepo->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Usuário não encontrado.');
        }

        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', 'Usuário removido com sucesso.');

        return $this->redirectToRoute('admin_user_index');
    }

    private function handleForm(Request $request, User $user, string $title): Response
    {
        // Verifica se a propriedade 'enabled' existe e usa o getter correto
        $enabled = false;
        if (method_exists($user, 'isEnabled')) {
            $enabled = $user->isEnabled();
        } elseif (method_exists($user, 'getEnabled')) {
            $enabled = $user->getEnabled();
        } elseif (property_exists($user, 'enabled')) {
            $reflection = new \ReflectionProperty($user, 'enabled');
            $reflection->setAccessible(true);
            $enabled = $reflection->getValue($user) ?? true;
        } else {
            $enabled = true;
        }

        $formData = [
            'name' => $user->getName() ?? '',
            'email' => $user->getEmail() ?? '',
            'password' => '',
            'roles' => $user->getRoles() ?? ['ROLE_USER'],
            'enabled' => $enabled,
        ];

        if ($request->isMethod('POST')) {
            $formData = [
                'name' => $request->request->get('name', ''),
                'email' => $request->request->get('email', ''),
                'password' => $request->request->get('password', ''),
                'roles' => $request->request->all('roles', []) ?: ['ROLE_USER'],
                'enabled' => $request->request->has('enabled'),
            ];

            $errors = $this->validateForm($formData, $user->getId());

            if (empty($errors)) {
                $user->setName($formData['name']);
                $user->setEmail($formData['email']);

                if (!empty($formData['password'])) {
                    $hashedPassword = $this->passwordHasher->hashPassword($user, $formData['password']);
                    $user->setPassword($hashedPassword);
                }

                $user->setRoles($formData['roles']);

                // Usa o setter correto
                if (method_exists($user, 'setEnabled')) {
                    $user->setEnabled($formData['enabled']);
                } elseif (method_exists($user, 'setActive')) {
                    $user->setActive($formData['enabled']);
                }

                $this->em->persist($user);
                $this->em->flush();

                $this->addFlash('success', $title . ' com sucesso!');

                return $this->redirectToRoute('admin_user_index');
            }

            $this->addFlash('error', 'Verifique os campos e tente novamente.');
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'formData' => $formData,
            'errors' => $errors ?? [],
            'title' => $title,
        ]);
    }

    private function validateForm(array $data, ?int $userId): array
    {
        $errors = [];

        if (empty(trim($data['name']))) {
            $errors['name'] = 'Nome é obrigatório.';
        }

        if (empty(trim($data['email']))) {
            $errors['email'] = 'Email é obrigatório.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email inválido.';
        } else {
            $existingUser = $this->userRepo->findOneBy(['email' => $data['email']]);
            if ($existingUser && $existingUser->getId() !== $userId) {
                $errors['email'] = 'Este email já está em uso.';
            }
        }

        if (empty($data['password']) && !$userId) {
            $errors['password'] = 'Senha é obrigatória.';
        } elseif (!empty($data['password']) && strlen($data['password']) < 6) {
            $errors['password'] = 'Senha deve ter pelo menos 6 caracteres.';
        }

        return $errors;
    }
}
