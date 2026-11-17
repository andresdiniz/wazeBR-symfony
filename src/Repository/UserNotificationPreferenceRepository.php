<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserNotificationPreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserNotificationPreference>
 */
class UserNotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserNotificationPreference::class);
    }

    public function findOneByUser(User $user): ?UserNotificationPreference
    {
        return $this->createQueryBuilder('p')
            ->addSelect('s')
            ->leftJoin('p.subscriptions', 's')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Carrega, em uma única consulta, as preferências (com regras) de vários usuários.
     *
     * @param list<User> $users
     *
     * @return array<int, UserNotificationPreference> indexado pelo id do usuário
     */
    public function findIndexedByUsers(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->addSelect('s')
            ->leftJoin('p.subscriptions', 's')
            ->andWhere('p.user IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $preference) {
            $userId = $preference->getUser()->getId();
            if ($userId !== null) {
                $indexed[$userId] = $preference;
            }
        }

        return $indexed;
    }
}
