<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeAlert>
 *
 * @method WazeAlert|null find($id, $lockMode = null, $lockVersion = null)
 * @method WazeAlert|null findOneBy(array $criteria, array $orderBy = null)
 * @method WazeAlert[] findAll()
 * @method WazeAlert[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WazeAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlert::class);
    }

    /**
     * Busca um alerta específico do Waze dentro de um parceiro.
     *
     * É necessário usar partner + uuid porque o mesmo UUID não deve
     * conflitar entre parceiros diferentes.
     */
    public function findOneByPartnerAndUuid(
        Partner $partner,
        string $uuid,
    ): ?WazeAlert {
        return $this->findOneBy([
            'partner' => $partner,
            'uuid' => $uuid,
        ]);
    }

    /**
     * Retorna todos os alertas ativos de um parceiro.
     *
     * @return WazeAlert[]
     */
    public function findActiveByPartner(
        Partner $partner,
        int $limit = 1000,
    ): array {
        return $this->createQueryBuilder('a')
            ->andWhere('a.partner = :partner')
            ->andWhere('a.isActive = :isActive')
            ->setParameter('partner', $partner)
            ->setParameter('isActive', true)
            ->orderBy('a.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Desativa os alertas ativos que pertencem ao partner, mas que não
     * foram recebidos no JSON atual e validado do Waze.
     *
     * NÃO chame este método se:
     * - a requisição retornou erro;
     * - a resposta não foi HTTP 200;
     * - o JSON é inválido;
     * - a chave "alerts" não existe;
     * - o retorno trouxe lista vazia por falha/ambiguidade.
     *
     * @param string[] $currentUuids UUIDs presentes no JSON atual
     */
    public function deactivateMissingForPartner(
        Partner $partner,
        array $currentUuids,
        \DateTimeImmutable $deactivatedAt,
    ): int {
        $currentUuids = array_values(array_unique(array_filter(
            $currentUuids,
            static fn (mixed $uuid): bool => is_string($uuid) && trim($uuid) !== '',
        )));

        /*
         * Segurança: sem UUIDs não há certeza de que o feed realmente
         * está vazio. Portanto não desativamos todo o banco.
         */
        if ($currentUuids === []) {
            return 0;
        }

        return $this->createQueryBuilder('a')
            ->update()
            ->set('a.isActive', ':inactive')
            ->set('a.deactivatedAt', ':deactivatedAt')
            ->where('a.partner = :partner')
            ->andWhere('a.isActive = :active')
            ->andWhere('a.uuid NOT IN (:uuids)')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->setParameter('inactive', false)
            ->setParameter('deactivatedAt', $deactivatedAt)
            ->setParameter('uuids', $currentUuids)
            ->getQuery()
            ->execute();
    }

    /**
     * Desativa manualmente todos os alerts ativos de um partner.
     *
     * Use somente em uma ação administrativa explícita.
     * Não use como consequência de uma falha da API.
     */
    public function deactivateAllForPartner(
        Partner $partner,
        \DateTimeImmutable $deactivatedAt,
    ): int {
        return $this->createQueryBuilder('a')
            ->update()
            ->set('a.isActive', ':inactive')
            ->set('a.deactivatedAt', ':deactivatedAt')
            ->where('a.partner = :partner')
            ->andWhere('a.isActive = :active')
            ->setParameter('partner', $partner)
            ->setParameter('active', true)
            ->setParameter('inactive', false)
            ->setParameter('deactivatedAt', $deactivatedAt)
            ->getQuery()
            ->execute();
    }

    /**
     * @return WazeAlert[]
     */
    public function findByCityAndType(
        string $city,
        string $type,
        int $limit = 100,
        bool $onlyActive = true,
    ): array {
        $queryBuilder = $this->createQueryBuilder('a')
            ->andWhere('a.city = :city')
            ->andWhere('a.type = :type')
            ->setParameter('city', $city)
            ->setParameter('type', $type);

        if ($onlyActive) {
            $queryBuilder
                ->andWhere('a.isActive = :isActive')
                ->setParameter('isActive', true);
        }

        return $queryBuilder
            ->orderBy('a.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WazeAlert[]
     */
    public function findRecentByCity(
        string $city,
        int $hours = 24,
        int $limit = 500,
        bool $onlyActive = true,
    ): array {
        $since = new \DateTimeImmutable(sprintf('-%d hours', $hours));
        $sinceMillis = $since->getTimestamp() * 1000;

        $queryBuilder = $this->createQueryBuilder('a')
            ->andWhere('a.city = :city')
            ->andWhere('a.pubMillis >= :since')
            ->setParameter('city', $city)
            ->setParameter('since', $sinceMillis);

        if ($onlyActive) {
            $queryBuilder
                ->andWhere('a.isActive = :isActive')
                ->setParameter('isActive', true);
        }

        return $queryBuilder
            ->orderBy('a.pubMillis', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WazeAlert[]
     */
    public function findByBoundingBox(
        float $minLon,
        float $minLat,
        float $maxLon,
        float $maxLat,
        int $limit = 1000,
        bool $onlyActive = true,
    ): array {
        $queryBuilder = $this->createQueryBuilder('a')
            ->andWhere('a.longitude BETWEEN :minLon AND :maxLon')
            ->andWhere('a.latitude BETWEEN :minLat AND :maxLat')
            ->setParameter('minLon', $minLon)
            ->setParameter('maxLon', $maxLon)
            ->setParameter('minLat', $minLat)
            ->setParameter('maxLat', $maxLat);

        if ($onlyActive) {
            $queryBuilder
                ->andWhere('a.isActive = :isActive')
                ->setParameter('isActive', true);
        }

        return $queryBuilder
            ->orderBy('a.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna a contagem dos tipos de alertas ativos por cidade.
     *
     * Exemplo:
     * [
     *     ['type' => 'HAZARD', 'count' => '32'],
     *     ['type' => 'ROAD_CLOSED', 'count' => '4'],
     * ]
     */
    public function getAlertCountByType(
        string $city,
        bool $onlyActive = true,
    ): array {
        $queryBuilder = $this->createQueryBuilder('a')
            ->select('a.type, COUNT(a.id) AS count')
            ->andWhere('a.city = :city')
            ->setParameter('city', $city);

        if ($onlyActive) {
            $queryBuilder
                ->andWhere('a.isActive = :isActive')
                ->setParameter('isActive', true);
        }

        return $queryBuilder
            ->groupBy('a.type')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna os UUIDs que já existem para aquele parceiro.
     *
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
            static fn (mixed $uuid): bool => is_string($uuid) && trim($uuid) !== '',
        )));

        if ($uuids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->select('a.uuid')
            ->andWhere('a.partner = :partner')
            ->andWhere('a.uuid IN (:uuids)')
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
     * Retorna alertas desativados recentemente para histórico/auditoria.
     *
     * @return WazeAlert[]
     */
    public function findRecentlyDeactivated(
        Partner $partner,
        int $hours = 24,
        int $limit = 500,
    ): array {
        $since = new \DateTimeImmutable(sprintf('-%d hours', $hours));

        return $this->createQueryBuilder('a')
            ->andWhere('a.partner = :partner')
            ->andWhere('a.isActive = :isActive')
            ->andWhere('a.deactivatedAt >= :since')
            ->setParameter('partner', $partner)
            ->setParameter('isActive', false)
            ->setParameter('since', $since)
            ->orderBy('a.deactivatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
