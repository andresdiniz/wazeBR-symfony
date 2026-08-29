<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return list<Notification>
     */
    public function findUnreadByUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.isRead = :isRead')
            ->setParameter('user', $user)
            ->setParameter('isRead', false)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    public function countUnreadByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.isRead = :isRead')
            ->setParameter('user', $user)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Notification>
     */
    public function findByPartner(
        Partner $partner,
        int $page = 1,
        int $limit = 30,
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);

        return $this->createQueryBuilder('n')
            ->where('n.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function existsForAlert(User $user, string $wazeId): bool
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.referenceId = :referenceId')
            ->setParameter('user', $user)
            ->setParameter('type', 'waze_alert')
            ->setParameter('referenceId', $wazeId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function existsForCemaden(
        User $user,
        string $stationCode,
        \DateTimeInterface $measuredAt,
    ): bool {
        $referenceId = sprintf(
            '%s_%s',
            $stationCode,
            $measuredAt->format('YmdHi'),
        );

        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.referenceId = :referenceId')
            ->setParameter('user', $user)
            ->setParameter('type', 'cemaden')
            ->setParameter('referenceId', $referenceId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function save(Notification $notification, bool $flush = true): void
    {
        $this->getEntityManager()->persist($notification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Notification $notification, bool $flush = true): void
    {
        $this->getEntityManager()->remove($notification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Disponibiliza o EntityManager para os commands que fazem flush em lote.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return parent::getEntityManager();
    }
}
