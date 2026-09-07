<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserRegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @throws \InvalidArgumentException Se houver violação de regras de negócio.
     */
    public function register(User $user): void
    {
        $plainPassword = $user->getPlainPassword();

        if (!$plainPassword) {
            throw new \InvalidArgumentException('Senha inválida.');
        }

        // Roles padrão para novo usuário
        $user->setRoles([User::ROLE_VIEWER]);

        // Hash da senha
        $hashed = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashed);

        // createdAt já é definido no construtor da entidade
        $user->setUpdatedAt(new \DateTimeImmutable());

        // Persist
        $this->em->persist($user);
        $this->em->flush();
    }
}
