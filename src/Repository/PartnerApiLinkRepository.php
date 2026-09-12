<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\PartnerApiLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PartnerApiLink>
 */
class PartnerApiLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerApiLink::class);
    }

    /**
     * Retorna o PartnerApiLink de um partner para um provider específico.
     * Exemplo: findOneByPartnerAndProvider($partner, 'waze_tvt')
     */
    public function findOneByPartnerAndProvider(Partner $partner, string $provider): ?PartnerApiLink
    {
        return $this->createQueryBuilder('pal')
            ->where('pal.partner = :partner')
            ->andWhere('pal.provider = :provider')
            ->setParameter('partner', $partner)
            ->setParameter('provider', $provider)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retorna todos os PartnerApiLinks de um provider, com partner carregado.
     * Útil para iterar sobre todos os parceiros que têm credenciais de um mesmo serviço.
     *
     * @return PartnerApiLink[]
     */
    public function findAllByProvider(string $provider): array
    {
        return $this->createQueryBuilder('pal')
            ->addSelect('p')
            ->join('pal.partner', 'p')
            ->where('pal.provider = :provider')
            ->setParameter('provider', $provider)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna todos os links de um partner.
     *
     * @return PartnerApiLink[]
     */
    public function findByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('pal')
            ->where('pal.partner = :partner')
            ->orderBy('pal.id', 'ASC')
            ->setParameter('partner', $partner)
            ->getQuery()
            ->getResult();
    }
}
