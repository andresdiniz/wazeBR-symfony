<?php

namespace App\Repository;

use App\Entity\WazeAlertType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WazeAlertType>
 */
class WazeAlertTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WazeAlertType::class);
    }

    /**
     * Retorna todos os tipos e subtipos agrupados para um locale.
     * Fallback para 'en' se o locale solicitado não existir.
     *
     * @return array<string, array{label: string, subtypes: array<string, string>}>
     */
    public function getGroupedByType(string $locale = 'pt'): array
    {
        $rows = $this->createQueryBuilder('t')
            ->where('t.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('t.type')
            ->addOrderBy('t.subtype')
            ->getQuery()
            ->getResult();

        if (empty($rows)) {
            $rows = $this->createQueryBuilder('t')
                ->where('t.locale = :locale')
                ->setParameter('locale', 'en')
                ->orderBy('t.type')
                ->addOrderBy('t.subtype')
                ->getQuery()
                ->getResult();
        }

        $grouped = [];
        /** @var WazeAlertType $row */
        foreach ($rows as $row) {
            $type = $row->getType();
            if (!isset($grouped[$type])) {
                $grouped[$type] = ['label' => '', 'subtypes' => []];
            }
            if ($row->getSubtype() === null) {
                $grouped[$type]['label'] = $row->getLabel();
            } else {
                $grouped[$type]['subtypes'][$row->getSubtype()] = $row->getLabel();
            }
        }

        return $grouped;
    }

    /**
     * Mapa simples "TYPE" => rótulo traduzido, para um locale.
     */
    public function getTypesMap(string $locale = 'pt'): array
    {
        $rows = $this->createQueryBuilder('t')
            ->where('t.locale = :locale AND t.subtype IS NULL')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getType()] = $row->getLabel();
        }
        return $map;
    }

    /**
     * Mapa "TYPE|SUBTYPE" => rótulo traduzido, para um locale (inclui subtipos).
     */
    public function getSubtypesMap(string $locale = 'pt'): array
    {
        $rows = $this->createQueryBuilder('t')
            ->where('t.locale = :locale AND t.subtype IS NOT NULL')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getType() . '|' . $row->getSubtype()] = $row->getLabel();
        }
        return $map;
    }
}
