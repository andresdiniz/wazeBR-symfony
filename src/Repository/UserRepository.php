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
            throw new UnsupportedUserException(sprintf('Instâncias de "%s" não são suportadas.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    public function updateLastLogin(User $user): void
    {
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->getEntityManager()->flush();
    }

    public function generateResetToken(User $user): void
    {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();
        $token = bin2hex(random_bytes(32));
        $conn->executeStatement(
            'UPDATE users SET reset_token = :token, reset_token_expires_at = :expires WHERE id = :id',
            [
                'token'   => $token,
                'expires' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
                'id'      => $user->getId(),
            ],
        );
    }

    public function findByResetToken(string $token): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.resetToken = :token')
            ->setParameter('token', $token)
            ->getQuery()->getOneOrNullResult();
    }

    public function isResetTokenValid(User $user): bool
    {
        $expires = $user->getResetTokenExpiresAt();
        return $expires !== null && $expires > new \DateTimeImmutable();
    }

    public function clearResetToken(User $user): void
    {
        $em = $this->getEntityManager();
        $em->getConnection()->executeStatement(
            'UPDATE users SET reset_token = NULL, reset_token_expires_at = NULL WHERE id = :id',
            ['id' => $user->getId()],
        );
    }

    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.partner = :p')->setParameter('p', $partner)
            ->orderBy('u.name', 'ASC')
            ->getQuery()->getResult();
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) $this->getEntityManager()->flush();
    }
}
