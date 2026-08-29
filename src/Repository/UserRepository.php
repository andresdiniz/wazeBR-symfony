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

    /**
     * Grava data/hora e IP do login bem-sucedido. Chamado pelo
     * LoginSuccessListener a cada autenticação (form_login e
     * remember-me automático).
     */
    public function recordLogin(User $user, ?string $ip): void
    {
        $user->setLastLoginAt(new \DateTimeImmutable());
        $user->setLastLoginIp($ip);
        $this->save($user);
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

    /**
     * Gera um token de redefinição de senha criptograficamente seguro,
     * persiste no usuário com prazo de expiração e retorna o token em texto puro
     * (o único momento em que ele existe fora do banco, para ir no e-mail).
     */
    public function generateResetToken(User $user, int $ttlMinutes = 60): string
    {
        $token = bin2hex(random_bytes(32));

        $user->setResetToken($token);
        $user->setResetTokenExpiresAt(new \DateTimeImmutable(sprintf('+%d minutes', $ttlMinutes)));

        $this->save($user);

        return $token;
    }

    /**
     * Busca um usuário por token de reset, apenas se ainda válido (não expirado).
     */
    public function findByResetToken(string $token): ?User
    {
        return $this->findByValidResetToken($token);
    }

    public function isResetTokenValid(User $user): bool
    {
        return $user->getResetToken() !== null
            && $user->getResetTokenExpiresAt() !== null
            && $user->getResetTokenExpiresAt() > new \DateTimeImmutable();
    }

    public function clearResetToken(User $user): void
    {
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);
        $this->save($user);
    }

    public function findAdminsByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.partner = :partner')
            ->andWhere('u.isActive = :active')
            ->andWhere('u.roles LIKE :adminRole')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }
    /**
 * Retorna todos os usuários ativos.
 *
 * Se um parceiro for informado, retorna somente usuários ativos
 * vinculados a esse parceiro.
 *
 * @return list<User>
 */
public function findActiveUsers(?Partner $partner = null): array
{
    $qb = $this->createQueryBuilder('u')
        ->where('u.isActive = :active')
        ->setParameter('active', true)
        ->orderBy('u.name', 'ASC')
        ->addOrderBy('u.id', 'ASC');

    if ($partner !== null) {
        $qb->andWhere('u.partner = :partner')
            ->setParameter('partner', $partner);
    }

    return $qb
        ->getQuery()
        ->getResult();
}
}
