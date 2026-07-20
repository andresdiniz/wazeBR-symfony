<?php

namespace App\Enum;

enum CifsTypeEnum: string
{
    case ROAD_CLOSED = 'ROAD_CLOSED';
    case ACCIDENT    = 'ACCIDENT';
    case HAZARD      = 'HAZARD';
    case POLICE      = 'POLICE';
    case JAM         = 'JAM';
    case CHIT_CHAT   = 'CHIT_CHAT';

    public function label(): string
    {
        return match($this) {
            self::ROAD_CLOSED => 'Via Interditada',
            self::ACCIDENT    => 'Acidente',
            self::HAZARD      => 'Perigo',
            self::POLICE      => 'Polícia',
            self::JAM         => 'Congestionamento',
            self::CHIT_CHAT   => 'Informação',
        };
    }

    public function emoji(): string
    {
        return match($this) {
            self::ROAD_CLOSED => '🚫',
            self::ACCIDENT    => '💥',
            self::HAZARD      => '⚠️',
            self::POLICE      => '🚔',
            self::JAM         => '🚗',
            self::CHIT_CHAT   => '💬',
        };
    }

    /** @return string[] */
    public function allowedSubtypes(): array
    {
        return match($this) {
            self::ROAD_CLOSED => [
                'ROAD_CLOSED_HAZARD',
                'ROAD_CLOSED_CONSTRUCTION',
                'ROAD_CLOSED_EVENT',
            ],
            self::ACCIDENT => [
                'ACCIDENT_MINOR',
                'ACCIDENT_MAJOR',
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
            self::JAM => [
                'JAM_LIGHT_TRAFFIC',
                'JAM_MODERATE_TRAFFIC',
                'JAM_HEAVY_TRAFFIC',
                'JAM_STAND_STILL_TRAFFIC',
            ],
            self::POLICE => [
                'POLICE_VISIBLE',
                'POLICE_HIDING',
                'POLICE_WITH_MOBILE_CAMERA',
            ],
            self::CHIT_CHAT => [],
        };
    }
}
