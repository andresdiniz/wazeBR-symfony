<?php

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Retorna administradores ativos vinculados ao parceiro.
     * Aceita tanto o papel ADMIN quanto ROLE_ADMIN, conforme o formato salvo.
     *
     * @return User[]
     */
    public function findAdminsByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.partner = :partner')
            ->andWhere('u.roles LIKE :adminRole OR u.roles LIKE :adminRoleWithPrefix')
            ->setParameter('partner', $partner)
            ->setParameter('adminRole', '%"ADMIN"%')
            ->setParameter('adminRoleWithPrefix', '%"ROLE_ADMIN"%')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Alternativa útil para o dispatcher: somente usuários com e-mail válido.
     *
     * @return User[]
     */
    public function findNotificationRecipientsByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.partner = :partner')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere('u.email <> :empty')
            ->andWhere('u.roles LIKE :adminRole OR u.roles LIKE :adminRoleWithPrefix')
            ->setParameter('partner', $partner)
            ->setParameter('empty', '')
            ->setParameter('adminRole', '%"ADMIN"%')
            ->setParameter('adminRoleWithPrefix', '%"ROLE_ADMIN"%')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
