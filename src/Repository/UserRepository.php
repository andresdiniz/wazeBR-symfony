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

class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instância de "%s" esperada.', User::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    public function findAdminsByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.partner = :p')->setParameter('p', $partner)
            ->andWhere('u.isActive = true')
            ->andWhere('u.roles LIKE :role')->setParameter('role', '%ROLE_ADMIN%')
            ->getQuery()->getResult();
    }

    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.partner = :p')->setParameter('p', $partner)
            ->andWhere('u.isActive = true')
            ->orderBy('u.name', 'ASC')
            ->getQuery()->getResult();
    }

    public function findByValidResetToken(string $token): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.resetToken = :token')->setParameter('token', $token)
            ->andWhere('u.resetTokenExpiresAt > :now')->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->getOneOrNullResult();
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) $this->getEntityManager()->flush();
    }
}
