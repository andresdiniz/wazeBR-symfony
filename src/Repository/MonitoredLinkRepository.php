<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredLink;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoredLink>
 *
 * Campos reais da entidade MonitoredLink:
 *   $url, $label, $feedFormat (int), $isActive, $createdAt, $lastCollectedAt
 *
 * NÃO existem: $type, $name
 * Convenção adotada para feedFormat:
 *   1 = feed Waze (alertas/jams)
 *   2 = feed TVT (tráfego)
 */
class MonitoredLinkRepository extends ServiceEntityRepository
{
    public const FORMAT_WAZE    = 1;
    public const FORMAT_TRAFFIC = 2;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoredLink::class);
    }

    public function save(MonitoredLink $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(MonitoredLink $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Feeds Waze ativos (feedFormat = 1) */
    public function findActiveWazeFeeds(): array
    {
        return $this->findActiveByFormat(self::FORMAT_WAZE);
    }

    /** Feeds TVT ativos (feedFormat = 2) */
    public function findActiveTrafficFeeds(): array
    {
        return $this->findActiveByFormat(self::FORMAT_TRAFFIC);
    }

    /** Feeds ativos por formato numérico */
    public function findActiveByFormat(int $feedFormat): array
    {
        return $this->createQueryBuilder('ml')
            ->join('ml.partner', 'p')
            ->where('ml.feedFormat = :fmt')
            ->andWhere('ml.isActive = true')
            ->andWhere('p.isActive = true')
            ->setParameter('fmt', $feedFormat)
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('ml.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @deprecated Use findActiveByFormat() com a constante FORMAT_*
     * Mantido por compatibilidade com chamadas legadas que passavam string 'feed'/'traffic'.
     */
    public function findActiveByType(string $type): array
    {
        $fmt = match ($type) {
            'traffic' => self::FORMAT_TRAFFIC,
            default   => self::FORMAT_WAZE,
        };

        return $this->findActiveByFormat($fmt);
    }

    /** Todos os links de um parceiro, ordenados por formato e label */
    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('ml')
            ->where('ml.partner = :partner')
            ->setParameter('partner', $partner)
            ->orderBy('ml.feedFormat', 'ASC')
            ->addOrderBy('ml.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Links de feeds Waze e TVT ativos de um parceiro */
    public function findActiveFeedsByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('ml')
            ->where('ml.partner = :partner')
            ->andWhere('ml.feedFormat IN (:formats)')
            ->andWhere('ml.isActive = true')
            ->setParameter('partner', $partner)
            ->setParameter('formats', [self::FORMAT_WAZE, self::FORMAT_TRAFFIC])
            ->getQuery()
            ->getResult();
    }
}
