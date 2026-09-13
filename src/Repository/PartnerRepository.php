<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partner::class);
    }

    public function findNextDuePartner(
        ?int $partnerId = null,
        bool $force = false,
    ): ?Partner {
        $queryBuilder = $this->createQueryBuilder('partner')
            ->orderBy('partner.lastFetchAt', 'ASC')
            ->addOrderBy('partner.id', 'ASC');

        if ($partnerId !== null) {
            $queryBuilder
                ->andWhere('partner.id = :partnerId')
                ->setParameter('partnerId', $partnerId);
        }

        $partners = $queryBuilder
            ->getQuery()
            ->getResult();

        foreach ($partners as $partner) {
            if ($force || $this->isDue($partner)) {
                return $partner;
            }
        }

        return null;
    }

    public function isDue(Partner $partner): bool
    {
        $lastFetchAt = $partner->getLastFetchAt();

        if ($lastFetchAt === null) {
            return true;
        }

        $frequency = max(
            1,
            $partner->getFetchFrequency() ?? 120,
        );

        $unit = mb_strtolower(
            trim(
                (string) (
                    $partner->getFetchFrequencyUnit()
                    ?? 's'
                ),
            ),
        );

        $seconds = match ($unit) {
            's',
            'sec',
            'secs',
            'second',
            'seconds',
            'segundo',
            'segundos' => $frequency,

            'm',
            'min',
            'mins',
            'minute',
            'minutes',
            'minuto',
            'minutos' => $frequency * 60,

            'h',
            'hour',
            'hours',
            'hora',
            'horas' => $frequency * 3600,

            default => $frequency,
        };

        return (
            time() - $lastFetchAt->getTimestamp()
        ) >= $seconds;
    }
}
