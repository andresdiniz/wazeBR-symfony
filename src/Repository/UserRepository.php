<?php

declare(strict_types=1);

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
     * Retorna usuários ativos de um parceiro que possuem e-mail válido.
     *
     * @return list<User>
     */
    public function findActiveUsersByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.partner = :partner')
            ->andWhere('u.isActive = :isActive')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere('u.email <> :emptyEmail')
            ->setParameter('partner', $partner)
            ->setParameter('isActive', true)
            ->setParameter('emptyEmail', '')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna somente os e-mails ativos de um parceiro.
     *
     * @return list<string>
     */
    public function findActiveEmailsByPartner(Partner $partner): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('u.email')
            ->andWhere('u.partner = :partner')
            ->andWhere('u.isActive = :isActive')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere('u.email <> :emptyEmail')
            ->setParameter('partner', $partner)
            ->setParameter('isActive', true)
            ->setParameter('emptyEmail', '')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $emails = [];

        foreach ($rows as $row) {
            $email = trim((string) ($row['email'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $emails[strtolower($email)] = $email;
        }

        return array_values($emails);
    }
}
