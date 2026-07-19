<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->remove($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAccountAdminsByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.partner = :partner')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('partner', $partner)
            ->setParameter('role', '%ROLE_ACCOUNT_ADMIN%')
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findFieldAgentsByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.partner = :partner')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('partner', $partner)
            ->setParameter('role', '%ROLE_FIELD_AGENT%')
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('LOWER(u.email) = LOWER(:email)')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Busca um usuário por token de redefinição de senha ainda válido
     * (não expirado). Tokens expirados ou inexistentes retornam null.
     */
    public function findByValidResetToken(string $token): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.resetToken = :token')
            ->andWhere('u.resetTokenExpiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
