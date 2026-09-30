<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Repository\PartnerRepository;

final class BriefingSchedulerService
{
    public function __construct(
        private readonly PartnerRepository $partnerRepository,
        private readonly string $strategy = 'always',
    ) {
    }

    /**
     * @return list<array{partner: Partner, cidade: string, nome: string, use_ai: bool}>
     */
    public function getParceirosPorDia(?\DateTimeImmutable $hoje = null): array
    {
        $hoje ??= new \DateTimeImmutable(
            'now',
            new \DateTimeZone('America/Sao_Paulo'),
        );

        $diaSemana = (int) $hoje->format('N');

        $partners = $this->partnerRepository->findBy(
            ['isActive' => true],
            ['id' => 'ASC'],
        );

        $validos = array_values(array_filter(
            $partners,
            static fn (Partner $partner): bool =>
                $partner->getId() !== null
                && trim((string) $partner->getCity()) !== '',
        ));

        $resultado = [];

        foreach ($validos as $indice => $partner) {
            $useAi = match ($this->strategy) {
                'rotate' => $this->rotateStrategy(
                    $indice,
                    count($validos),
                    $diaSemana,
                ),
                'weekly' => (($partner->getId() - 1) % 7) + 1 === $diaSemana,
                default => true,
            };

            $resultado[] = [
                'partner' => $partner,
                'cidade' => trim((string) $partner->getCity()),
                'nome' => trim((string) $partner->getName())
                    ?: trim((string) $partner->getCity()),
                'use_ai' => $useAi,
            ];
        }

        return $resultado;
    }

    /**
     * @return list<array{partner: Partner, cidade: string, nome: string, use_ai: bool}>
     */
    public function getParceiroComIA(?\DateTimeImmutable $hoje = null): array
    {
        return array_values(array_filter(
            $this->getParceirosPorDia($hoje),
            static fn (array $item): bool => $item['use_ai'],
        ));
    }

    private function rotateStrategy(
        int $indice,
        int $total,
        int $diaSemana,
    ): bool {
        if ($diaSemana >= 6) {
            return true;
        }

        if ($total === 0) {
            return false;
        }

        // Distribui todos os parceiros em cinco grupos, sem limite de dez.
        $grupo = (int) floor($indice * 5 / $total);

        return $grupo === $diaSemana - 1;
    }
}
