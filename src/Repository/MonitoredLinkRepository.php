<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredLink;
use App\Entity\Partner;
use App\Enum\LinkType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoredLink>
 *
 * Campos reais da entidade MonitoredLink:
 *   $url, $label, $feedFormat (int), $linkType (LinkType enum), $isActive
 *
 * Convenção para feedFormat:
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

    public function countByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('ml')
            ->select('COUNT(ml.id)')
            ->where('ml.partner = :p')
            ->setParameter('p', $partner)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Feeds Waze PartnerHub ativos (feedFormat=1 E linkType='waze_feed').
     */
    public function findActiveWazeFeeds(): array
    {
        return $this->createQueryBuilder('ml')
            ->join('ml.partner', 'p')
            ->where('ml.feedFormat = :fmt')
            ->andWhere('ml.linkType = :type')
            ->andWhere('ml.isActive = true')
            ->andWhere('p.isActive = true')
            ->setParameter('fmt', self::FORMAT_WAZE)
            ->setParameter('type', LinkType::WazeFeed->value)
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('ml.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Links TVT ativos (linkType='waze_tvt').
     *
     * @return MonitoredLink[]
     */
    public function findActiveWazeTvtLinks(): array
    {
        return $this->createQueryBuilder('ml')
            ->join('ml.partner', 'p')
            ->where('ml.linkType = :type')
            ->andWhere('ml.isActive = true')
            ->andWhere('p.isActive = true')
            ->setParameter('type', LinkType::WazeTvt->value)
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('ml.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Feeds TVT ativos (feedFormat = 2) */
    public function findActiveTrafficFeeds(): array
    {
        return $this->findActiveByFormat(self::FORMAT_TRAFFIC);
    }

    /** Feeds ativos por formato numérico (sem filtro de linkType) */
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

    /**
     * Retorna o primeiro MonitoredLink do tipo waze_tvt ativo para um dado parceiro.
     */
    public function findOneTvtLinkByPartner(Partner $partner): ?MonitoredLink
    {
        return $this->createQueryBuilder('ml')
            ->where('ml.partner = :partner')
            ->andWhere('ml.linkType = :type')
            ->andWhere('ml.isActive = true')
            ->setParameter('partner', $partner)
            ->setParameter('type', LinkType::WazeTvt->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
