<?php

namespace App\Service;

/**
 * Codificação de geohash em PHP puro (sem dependência externa).
 * Algoritmo padrão Gustavo Niemeyer.
 */
class GeohashService
{
    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    public function encode(float $lat, float $lon, int $precision = 8): string
    {
        $minLat = -90.0;
        $maxLat = 90.0;
        $minLon = -180.0;
        $maxLon = 180.0;

        $hash = '';
        $bits = 0;
        $bitsTotal = 0;
        $hashValue = 0;
        $isEven = true;

        while (strlen($hash) < $precision) {
            if ($isEven) {
                $mid = ($minLon + $maxLon) / 2;
                if ($lon >= $mid) {
                    $hashValue = ($hashValue << 1) | 1;
                    $minLon = $mid;
                } else {
                    $hashValue = $hashValue << 1;
                    $maxLon = $mid;
                }
            } else {
                $mid = ($minLat + $maxLat) / 2;
                if ($lat >= $mid) {
                    $hashValue = ($hashValue << 1) | 1;
                    $minLat = $mid;
                } else {
                    $hashValue = $hashValue << 1;
                    $maxLat = $mid;
                }
            }

            $isEven = !$isEven;
            $bits++;
            $bitsTotal++;

            if ($bits === 5) {
                $hash .= self::BASE32[$hashValue];
                $bits = 0;
                $hashValue = 0;
            }
        }

        return $hash;
    }
}
