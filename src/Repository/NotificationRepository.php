<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function findUnreadByUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')->setParameter('user', $user)
            ->andWhere('n.isRead = false')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function countUnreadByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')->setParameter('user', $user)
            ->andWhere('n.isRead = false')
            ->getQuery()->getSingleScalarResult();
    }

    public function findByPartner(Partner $partner, int $page = 1, int $limit = 30): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.partner = :p')->setParameter('p', $partner)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function existsForAlert(User $user, string $wazeId): bool
    {
        return (bool) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')->setParameter('user', $user)
            ->andWhere('n.type = :type')->setParameter('type', 'waze_alert')
            ->andWhere('n.referenceId = :ref')->setParameter('ref', $wazeId)
            ->getQuery()->getSingleScalarResult();
    }

    public function existsForCemaden(User $user, string $stationCode, \DateTimeImmutable $measuredAt): bool
    {
        $ref = $stationCode . '_' . $measuredAt->format('YmdHi');
        return (bool) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')->setParameter('user', $user)
            ->andWhere('n.type = :type')->setParameter('type', 'cemaden')
            ->andWhere('n.referenceId = :ref')->setParameter('ref', $ref)
            ->getQuery()->getSingleScalarResult();
    }

    public function save(Notification $n, bool $flush = true): void
    {
        $this->getEntityManager()->persist($n);
        if ($flush) $this->getEntityManager()->flush();
    }
}
