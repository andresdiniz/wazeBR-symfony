<?php

declare(strict_types=1);

namespace App\Service;

final class ForecastHistoryWindows
{
    public static function validateSlot(int $minutes): void
    {
        if ($minutes < 5 || $minutes > 60 || 60 % $minutes !== 0) {
            throw new \InvalidArgumentException('A janela deve ter entre 5 e 60 minutos e dividir uma hora exatamente.');
        }
    }

    /** @return list<array{start:string,end:string}> UTC, start inclusive/end exclusive. */
    public static function build(\DateTimeImmutable $target, int $slotMinutes, int $historyDays, \DateTimeImmutable $asOf, \DateTimeZone $timezone): array
    {
        self::validateSlot($slotMinutes);
        if ($historyDays < 1 || $historyDays > 366) {
            throw new \InvalidArgumentException('O historico deve ter entre 1 e 366 dias.');
        }
        $utc = new \DateTimeZone('UTC');
        $target = $target->setTimezone($timezone);
        $localNow = $asOf->setTimezone($timezone);
        $cutoff = $localNow->modify('-' . $historyDays . ' days');
        $day = $localNow->setTime(12, 0, 0);
        $out = [];
        for ($i = 0; $i <= $historyDays; ++$i) {
            $candidate = $day->modify('-' . $i . ' days');
            if ($candidate->format('N') !== $target->format('N')) continue;
            $start = $candidate->setTime((int) $target->format('H'), (int) $target->format('i'), 0);
            $end = $start->modify('+' . $slotMinutes . ' minutes');
            if ($start < $cutoff || $end > $asOf || $end <= $start) continue;
            $out[] = ['start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'end' => $end->setTimezone($utc)->format('Y-m-d H:i:s')];
        }
        return $out;
    }
}
