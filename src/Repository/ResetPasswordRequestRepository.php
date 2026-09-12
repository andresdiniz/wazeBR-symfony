<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Repository\ResetPasswordRequestRepositoryInterface;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        return new ResetPasswordRequest($user, $expiresAt, $selector, $hashedToken);
    }

    public function getUserIdentifier(): string
    {
        return 'id';
    }

    public function findAllNonExpiredRequestsForUser(object $user): array
    {
        return $this->createQueryBuilder('rpr')
            ->where('rpr.user = :user')
            ->setParameter('user', $user)
            ->andWhere('rpr.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->getEntityManager()->remove($resetPasswordRequest);
        $this->getEntityManager()->flush();
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        $now = new \DateTimeImmutable();
        $qb = $this->createQueryBuilder('rpr')
            ->delete()
            ->where('rpr.expiresAt < :now')
            ->setParameter('now', $now);

        return $qb->getQuery()->execute();
    }

    public function findResetPasswordRequest(string $selector): ?ResetPasswordRequestInterface
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        $qb = $this->createQueryBuilder('rpr')
            ->select('rpr.expiresAt')
            ->where('rpr.user = :user')
            ->setParameter('user', $user)
            ->orderBy('rpr.expiresAt', 'DESC')
            ->setMaxResults(1);

        $result = $qb->getQuery()->getOneOrNullResult();

        if ($result) {
            return $result['expiresAt'];
        }

        return null;
    }
}
