<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActivityLog;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /**
     * Retorna logs de erro de agendamento vinculados a um parceiro específico.
     * Actions consideradas "erro de agendamento": fetch_error, parse_error, schedule_error.
     *
     * @return ActivityLog[]
     */
    public function findErrorsByPartner(
        Partner $partner,
        int $page  = 1,
        int $limit = 50,
    ): array {
        return $this->createQueryBuilder('l')
            ->where('l.partner = :partner')
            ->andWhere('l.action IN (:actions)')
            ->setParameter('partner', $partner)
            ->setParameter('actions', ['fetch_error', 'parse_error', 'schedule_error'])
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult(($page - 1) * $limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Conta total de erros de um parceiro (para paginação).
     */
    public function countErrorsByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.partner = :partner')
            ->andWhere('l.action IN (:actions)')
            ->setParameter('partner', $partner)
            ->setParameter('actions', ['fetch_error', 'parse_error', 'schedule_error'])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
