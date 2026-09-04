<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findForLogin(string $identifier): ?User
    {
        return $this->findOneBy(['email' => $identifier]);
    }

    /**
     * @deprecated Metodo removido - User nao tem mais lastLoginAt/lastLoginIp
     */
    public function recordLogin(User $user, ?string $ip): void
    {
        // Metodo nao faz mais nada - campos removidos da entidade User
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface|UserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->save($user, true);
    }

    /**
     * Conta quantos usuários pertencem a um parceiro.
     */
    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.partner = :partner')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Busca usuários por parceiro.
     */
    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.partner = :partner')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
