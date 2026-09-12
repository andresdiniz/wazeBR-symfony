<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeJam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeJam>
 *
 * @method WazeJam|null find($id, $lockMode = null, $lockVersion = null)
 * @method WazeJam|null findOneBy(array $criteria, array $orderBy = null)
 * @method WazeJam[] findAll()
 * @method WazeJam[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WazeJamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeJam::class);
    }

    public function findOneByPartnerAndUuid(
        Partner $partner,
        string $uuid,
    ): ?WazeJam {
        return $this->findOneBy([
            'partner' => $partner,
            'uuid' => $uuid,
        ]);
    }

    /**
     * @return WazeJam[]
     */
    public function findActiveByPartner(
        Partner $partner,
        int $limit = 1000,
    ): array {
        return $this->createQueryBuilder('j')
            ->andWhere('j.partner = :partner')
            ->andWhere('j.isActive = :isActive')
            ->setParameter('partner', $partner)
            ->setParameter('isActive', true)
            ->orderBy('j.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Desativa os jams ativos do partner que não apareceram no
     * último JSON válido.
     *
     * Este método só deve ser chamado após:
     * - HTTP 200;
     * - JSON válido;
     * - chave "jams" validada;
     * - lista processada corretamente.
     *
     * @param string[] $currentUuids
     */
    public function deactivateMissingForPartner(
        Partner $partner,
        array $currentUuids,
        \DateTimeImmutable $deactivatedAt,
    ): int {
        $currentUuids = array_values(array_unique(array_filter(
            $currentUuids,
            static fn (mixed $uuid): bool =>
                is_string($uuid) && trim($uuid) !== '',
        )));

        /*
         * Por segurança, uma lista vazia não desativa todos os registros.
         * Isso evita desativação em caso de resposta parcial ou falha.
         */
        if ($currentUuids === []) {
            return 0;
        }

        return $this->createQueryBuilder('j')
            ->update()
            ->set('j.isActive', ':inactive')
            ->set('j.deactivatedAt', ':deactivatedAt')
            ->where('j.partner = :partner')
            ->andWhere('j.isActive = :active')
            ->andWhere('j.uuid NOT IN (:uuids)')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->setParameter('inactive', false)
            ->setParameter('deactivatedAt', $deactivatedAt)
            ->setParameter('uuids', $currentUuids)
            ->getQuery()
            ->execute();
    }

    /**
     * Desativa todos os jams ativos de um partner.
     *
     * Use somente para uma ação administrativa explícita.
     */
    public function deactivateAllForPartner(
        Partner $partner,
        \DateTimeImmutable $deactivatedAt,
    ): int {
        return $this->createQueryBuilder('j')
            ->update()
            ->set('j.isActive', ':inactive')
            ->set('j.deactivatedAt', ':deactivatedAt')
            ->where('j.partner = :partner')
            ->andWhere('j.isActive = :active')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->setParameter('inactive', false)
            ->setParameter('deactivatedAt', $deactivatedAt)
            ->getQuery()
            ->execute();
    }

    /**
     * @return WazeJam[]
     */
    public function findActiveByCity(
        string $city,
        int $limit = 100,
    ): array {
        return $this->createQueryBuilder('j')
            ->andWhere('j.city = :city')
            ->andWhere('j.isActive = :isActive')
            ->andWhere('j.level >= :minimumLevel')
            ->setParameter('city', $city)
            ->setParameter('isActive', true)
            ->setParameter('minimumLevel', 3)
            ->orderBy('j.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WazeJam[]
     */
    public function findActiveByPartnerAndCity(
        Partner $partner,
        string $city,
        int $limit = 100,
    ): array {
        return $this->createQueryBuilder('j')
            ->andWhere('j.partner = :partner')
            ->andWhere('j.city = :city')
            ->andWhere('j.isActive = :isActive')
            ->setParameter('partner', $partner)
            ->setParameter('city', $city)
            ->setParameter('isActive', true)
            ->orderBy('j.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByBlockingAlertUuid(
        string $alertUuid,
        ?Partner $partner = null,
    ): ?WazeJam {
        $queryBuilder = $this->createQueryBuilder('j')
            ->andWhere('j.blockingAlertUuid = :uuid')
            ->andWhere('j.isActive = :isActive')
            ->setParameter('uuid', $alertUuid)
            ->setParameter('isActive', true)
            ->orderBy('j.lastSeenAt', 'DESC')
            ->setMaxResults(1);

        if ($partner !== null) {
            $queryBuilder
                ->andWhere('j.partner = :partner')
                ->setParameter('partner', $partner);
        }

        return $queryBuilder
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return WazeJam[]
     */
    public function findRecentJams(
        int $hours = 6,
        int $limit = 500,
        bool $onlyActive = true,
    ): array {
        $since = new \DateTimeImmutable(sprintf('-%d hours', $hours));
        $sinceMillis = $since->getTimestamp() * 1000;

        $queryBuilder = $this->createQueryBuilder('j')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('since', $sinceMillis);

        if ($onlyActive) {
            $queryBuilder
                ->andWhere('j.isActive = :isActive')
                ->setParameter('isActive', true);
        }

        return $queryBuilder
            ->orderBy('j.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WazeJam[]
     */
    public function findRecentByPartner(
        Partner $partner,
        int $hours = 6,
        int $limit = 500,
        bool $onlyActive = true,
    ): array {
        $since = new \DateTimeImmutable(sprintf('-%d hours', $hours));
        $sinceMillis = $since->getTimestamp() * 1000;

        $queryBuilder = $this->createQueryBuilder('j')
            ->andWhere('j.partner = :partner')
            ->andWhere('j.pubMillis >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('since', $sinceMillis);

        if ($onlyActive) {
            $queryBuilder
                ->andWhere('j.isActive = :isActive')
                ->setParameter('isActive', true);
        }

        return $queryBuilder
            ->orderBy('j.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WazeJam[]
     */
    public function findByLevel(
        int $minimumLevel = 3,
        int $limit = 500,
        bool $onlyActive = true,
    ): array {
        $queryBuilder = $this->createQueryBuilder('j')
            ->andWhere('j.level >= :minimumLevel')
            ->setParameter('minimumLevel', $minimumLevel);

        if ($onlyActive) {
            $queryBuilder
                ->andWhere('j.isActive = :isActive')
                ->setParameter('isActive', true);
        }

        return $queryBuilder
            ->orderBy('j.level', 'DESC')
            ->addOrderBy('j.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getJamStatsByCity(
        bool $onlyActive = true,
    ): array {
        $queryBuilder = $this->createQueryBuilder('j')
            ->select(
                'j.city AS city',
                'COUNT(j.id) AS total',
                'AVG(j.level) AS avgLevel',
                'MAX(j.level) AS maxLevel',
            )
            ->groupBy('j.city')
            ->orderBy('total', 'DESC');

        if ($onlyActive) {
            $queryBuilder
                ->andWhere('j.isActive = :isActive')
                ->setParameter('isActive', true);
        }

        return $queryBuilder
            ->getQuery()
            ->getResult();
    }

    public function countActiveByPartner(Partner $partner): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.partner = :partner')
            ->andWhere('j.isActive = :isActive')
            ->setParameter('partner', $partner)
            ->setParameter('isActive', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param string[] $uuids
     *
     * @return string[]
     */
    public function findExistingUuidsForPartner(
        Partner $partner,
        array $uuids,
    ): array {
        $uuids = array_values(array_unique(array_filter(
            $uuids,
            static fn (mixed $uuid): bool =>
                is_string($uuid) && trim($uuid) !== '',
        )));

        if ($uuids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('j')
            ->select('j.uuid')
            ->andWhere('j.partner = :partner')
            ->andWhere('j.uuid IN (:uuids)')
            ->setParameter('partner', $partner)
            ->setParameter('uuids', $uuids)
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row): string => (string) $row['uuid'],
            $rows,
        );
    }

    /**
     * @return WazeJam[]
     */
    public function findRecentlyDeactivated(
        Partner $partner,
        int $hours = 24,
        int $limit = 500,
    ): array {
        $since = new \DateTimeImmutable(sprintf('-%d hours', $hours));

        return $this->createQueryBuilder('j')
            ->andWhere('j.partner = :partner')
            ->andWhere('j.isActive = :isActive')
            ->andWhere('j.deactivatedAt >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('isActive', false)
            ->setParameter('since', $since)
            ->orderBy('j.deactivatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
