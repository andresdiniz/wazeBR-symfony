<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Tipos e subtipos válidos da especificação CIFS (Closure & Incident Feed Spec)
 * do Waze. Fonte única de verdade para validação (formulário, controller, feed).
 *
 * Referência: https://developers.google.com/waze/data-feed/cifs-specification
 * (lista de subtipos conferida com a versão de 02/09/2025).
 *
 * Observação: a spec não define subtipos para CHIT_CHAT; nesse tipo o subtype
 * deve ser omitido.
 */
enum CifsTypeEnum: string
{
    case ACCIDENT = 'ACCIDENT';
    case JAM = 'JAM';
    case HAZARD = 'HAZARD';
    case POLICE = 'POLICE';
    case CHIT_CHAT = 'CHIT_CHAT';
    case ROAD_CLOSED = 'ROAD_CLOSED';

    /** @return string[] Subtipos permitidos para este tipo, segundo o CIFS. */
    public function allowedSubtypes(): array
    {
        return match ($this) {
            self::ACCIDENT => [
                'ACCIDENT_MINOR',
                'ACCIDENT_MAJOR',
            ],
            self::JAM => [
                'JAM_LIGHT_TRAFFIC',
                'JAM_MODERATE_TRAFFIC',
                'JAM_HEAVY_TRAFFIC',
                'JAM_STAND_STILL_TRAFFIC',
            ],
            self::HAZARD => [
                'HAZARD_ON_ROAD',
                'HAZARD_ON_ROAD_CAR_STOPPED',
                'HAZARD_ON_ROAD_CONSTRUCTION',
                'HAZARD_ON_ROAD_EMERGENCY_VEHICLE',
                'HAZARD_ON_ROAD_ICE',
                'HAZARD_ON_ROAD_LANE_CLOSED',
                'HAZARD_ON_ROAD_OBJECT',
                'HAZARD_ON_ROAD_OIL',
                'HAZARD_ON_ROAD_POT_HOLE',
                'HAZARD_ON_ROAD_ROAD_KILL',
                'HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT',
                'HAZARD_ON_SHOULDER',
                'HAZARD_ON_SHOULDER_ANIMALS',
                'HAZARD_ON_SHOULDER_CAR_STOPPED',
                'HAZARD_ON_SHOULDER_MISSING_SIGN',
                'HAZARD_WEATHER',
                'HAZARD_WEATHER_FLOOD',
                'HAZARD_WEATHER_FOG',
                'HAZARD_WEATHER_FREEZING_RAIN',
                'HAZARD_WEATHER_HAIL',
                'HAZARD_WEATHER_HEAT_WAVE',
                'HAZARD_WEATHER_HEAVY_RAIN',
                'HAZARD_WEATHER_HEAVY_SNOW',
                'HAZARD_WEATHER_HURRICANE',
                'HAZARD_WEATHER_MONSOON',
                'HAZARD_WEATHER_TORNADO',
            ],
            self::POLICE => [
                'POLICE_VISIBLE',
                'POLICE_HIDING',
                'POLICE_WITH_MOBILE_CAMERA',
            ],
            self::CHIT_CHAT => [],
            self::ROAD_CLOSED => [
                'ROAD_CLOSED_HAZARD',
                'ROAD_CLOSED_CONSTRUCTION',
                'ROAD_CLOSED_EVENT',
            ],
        };
    }

    /** Subtype vazio/nulo é aceito (na spec o subtype é "requested", não "required"). */
    public function isValidSubtype(?string $subtype): bool
    {
        if ($subtype === null || $subtype === '') {
            return true;
        }

        return \in_array($subtype, $this->allowedSubtypes(), true);
    }
}
