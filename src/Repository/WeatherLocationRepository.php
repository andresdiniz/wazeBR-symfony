<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WeatherLocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WeatherLocation>
 */
class WeatherLocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeatherLocation::class);
    }

    /**
     * Retorna todas as WeatherLocations ativas de um partner.
     *
     * @return WeatherLocation[]
     */
    public function findActiveByPartner(Partner $partner): array
    {
        return $this->createQueryBuilder('wl')
            ->where('wl.partner = :partner')
            ->andWhere('wl.active = true')
            ->setParameter('partner', $partner)
            ->orderBy('wl.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna todas as WeatherLocations ativas de todos os partners.
     * Eager-load do partner para evitar N+1 nos commands.
     *
     * @return WeatherLocation[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('wl')
            ->addSelect('p')
            ->join('wl.partner', 'p')
            ->where('wl.active = true')
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('wl.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna locations ativas cujo provider corresponde ao informado.
     *
     * @return WeatherLocation[]
     */
    public function findActiveByProvider(string $provider): array
    {
        return $this->createQueryBuilder('wl')
            ->addSelect('p')
            ->join('wl.partner', 'p')
            ->where('wl.active = true')
            ->andWhere('wl.provider = :provider')
            ->setParameter('provider', $provider)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
