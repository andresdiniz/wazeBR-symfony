<?php

namespace App\DataFixtures;

use App\Entity\WazeAlertType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Traduções pt/en/es para os tipos e subtipos do feed de LEITURA do Waze
 * (Traffic View / alertas repassados por Wazers), conforme a tabela oficial:
 * @see https://support.google.com/waze/partners/answer/13658466
 *
 * Nota da doc: "for historical reasons, HAZARD and WEATHERHAZARD types are
 * used interchangeably" — por isso os dois tipos aparecem aqui com os
 * mesmos subtipos.
 */
class WazeAlertTypeFixtures extends Fixture
{
    /** Dados: [type, subtype|null, locale, label] */
    private const DATA = [
        // ── ACCIDENT ─────────────────────────────────────────────
        ['ACCIDENT', null, 'pt', 'Acidente'],
        ['ACCIDENT', null, 'en', 'Accident'],
        ['ACCIDENT', null, 'es', 'Accidente'],
        ['ACCIDENT', 'ACCIDENT_MINOR', 'pt', 'Acidente leve'],
        ['ACCIDENT', 'ACCIDENT_MINOR', 'en', 'Minor accident'],
        ['ACCIDENT', 'ACCIDENT_MINOR', 'es', 'Accidente leve'],
        ['ACCIDENT', 'ACCIDENT_MAJOR', 'pt', 'Acidente grave'],
        ['ACCIDENT', 'ACCIDENT_MAJOR', 'en', 'Major accident'],
        ['ACCIDENT', 'ACCIDENT_MAJOR', 'es', 'Accidente grave'],
        ['ACCIDENT', 'NO_SUBTYPE', 'pt', 'Sem subtipo específico'],
        ['ACCIDENT', 'NO_SUBTYPE', 'en', 'No specific subtype'],
        ['ACCIDENT', 'NO_SUBTYPE', 'es', 'Sin subtipo específico'],

        // ── JAM ──────────────────────────────────────────────────
        ['JAM', null, 'pt', 'Congestionamento'],
        ['JAM', null, 'en', 'Jam'],
        ['JAM', null, 'es', 'Congestión'],
        ['JAM', 'JAM_LIGHT_TRAFFIC', 'pt', 'Trânsito leve'],
        ['JAM', 'JAM_LIGHT_TRAFFIC', 'en', 'Light traffic'],
        ['JAM', 'JAM_LIGHT_TRAFFIC', 'es', 'Tráfico ligero'],
        ['JAM', 'JAM_MODERATE_TRAFFIC', 'pt', 'Trânsito moderado'],
        ['JAM', 'JAM_MODERATE_TRAFFIC', 'en', 'Moderate traffic'],
        ['JAM', 'JAM_MODERATE_TRAFFIC', 'es', 'Tráfico moderado'],
        ['JAM', 'JAM_HEAVY_TRAFFIC', 'pt', 'Trânsito intenso'],
        ['JAM', 'JAM_HEAVY_TRAFFIC', 'en', 'Heavy traffic'],
        ['JAM', 'JAM_HEAVY_TRAFFIC', 'es', 'Tráfico intenso'],
        ['JAM', 'JAM_STAND_STILL_TRAFFIC', 'pt', 'Trânsito parado'],
        ['JAM', 'JAM_STAND_STILL_TRAFFIC', 'en', 'Stand-still traffic'],
        ['JAM', 'JAM_STAND_STILL_TRAFFIC', 'es', 'Tráfico detenido'],
        ['JAM', 'NO_SUBTYPE', 'pt', 'Sem subtipo específico'],
        ['JAM', 'NO_SUBTYPE', 'en', 'No specific subtype'],
        ['JAM', 'NO_SUBTYPE', 'es', 'Sin subtipo específico'],

        // ── MISC ─────────────────────────────────────────────────
        ['MISC', null, 'pt', 'Diversos'],
        ['MISC', null, 'en', 'Miscellaneous'],
        ['MISC', null, 'es', 'Varios'],
        ['MISC', 'NO_SUBTYPE', 'pt', 'Sem subtipo específico'],
        ['MISC', 'NO_SUBTYPE', 'en', 'No specific subtype'],
        ['MISC', 'NO_SUBTYPE', 'es', 'Sin subtipo específico'],

        // ── CONSTRUCTION ─────────────────────────────────────────
        ['CONSTRUCTION', null, 'pt', 'Obra'],
        ['CONSTRUCTION', null, 'en', 'Construction'],
        ['CONSTRUCTION', null, 'es', 'Obras'],
        ['CONSTRUCTION', 'NO_SUBTYPE', 'pt', 'Sem subtipo específico'],
        ['CONSTRUCTION', 'NO_SUBTYPE', 'en', 'No specific subtype'],
        ['CONSTRUCTION', 'NO_SUBTYPE', 'es', 'Sin subtipo específico'],

        // ── ROAD_CLOSED ──────────────────────────────────────────
        ['ROAD_CLOSED', null, 'pt', 'Via Interditada'],
        ['ROAD_CLOSED', null, 'en', 'Road Closed'],
        ['ROAD_CLOSED', null, 'es', 'Vía Cerrada'],
        ['ROAD_CLOSED', 'ROAD_CLOSED_HAZARD', 'pt', 'Interditada por perigo'],
        ['ROAD_CLOSED', 'ROAD_CLOSED_HAZARD', 'en', 'Closed due to hazard'],
        ['ROAD_CLOSED', 'ROAD_CLOSED_HAZARD', 'es', 'Cerrada por peligro'],
        ['ROAD_CLOSED', 'ROAD_CLOSED_CONSTRUCTION', 'pt', 'Interditada por obra'],
        ['ROAD_CLOSED', 'ROAD_CLOSED_CONSTRUCTION', 'en', 'Closed for construction'],
        ['ROAD_CLOSED', 'ROAD_CLOSED_CONSTRUCTION', 'es', 'Cerrada por obras'],
        ['ROAD_CLOSED', 'ROAD_CLOSED_EVENT', 'pt', 'Interditada por evento'],
        ['ROAD_CLOSED', 'ROAD_CLOSED_EVENT', 'en', 'Closed for event'],
        ['ROAD_CLOSED', 'ROAD_CLOSED_EVENT', 'es', 'Cerrada por evento'],
        ['ROAD_CLOSED', 'NO_SUBTYPE', 'pt', 'Sem subtipo específico'],
        ['ROAD_CLOSED', 'NO_SUBTYPE', 'en', 'No specific subtype'],
        ['ROAD_CLOSED', 'NO_SUBTYPE', 'es', 'Sin subtipo específico'],
    ];

    /**
     * HAZARD e WEATHERHAZARD compartilham exatamente os mesmos subtipos
     * (nota oficial: "used interchangeably; determine nature via subtype").
     * Dados: [subtype, locale, label]
     */
    private const HAZARD_SUBTYPES = [
        ['HAZARD_ON_ROAD',                    'pt', 'Perigo na via'],
        ['HAZARD_ON_ROAD',                    'en', 'Hazard on road'],
        ['HAZARD_ON_ROAD',                    'es', 'Peligro en la vía'],
        ['HAZARD_ON_ROAD_CAR_STOPPED',        'pt', 'Veículo parado na via'],
        ['HAZARD_ON_ROAD_CAR_STOPPED',        'en', 'Car stopped on road'],
        ['HAZARD_ON_ROAD_CAR_STOPPED',        'es', 'Vehículo detenido en la vía'],
        ['HAZARD_ON_ROAD_CONSTRUCTION',       'pt', 'Obra na via'],
        ['HAZARD_ON_ROAD_CONSTRUCTION',       'en', 'Construction on road'],
        ['HAZARD_ON_ROAD_CONSTRUCTION',       'es', 'Obras en la vía'],
        ['HAZARD_ON_ROAD_ICE',                'pt', 'Gelo na pista'],
        ['HAZARD_ON_ROAD_ICE',                'en', 'Ice on road'],
        ['HAZARD_ON_ROAD_ICE',                'es', 'Hielo en la vía'],
        ['HAZARD_ON_ROAD_LANE_CLOSED',        'pt', 'Faixa fechada'],
        ['HAZARD_ON_ROAD_LANE_CLOSED',        'en', 'Lane closed'],
        ['HAZARD_ON_ROAD_LANE_CLOSED',        'es', 'Carril cerrado'],
        ['HAZARD_ON_ROAD_OBJECT',             'pt', 'Objeto na pista'],
        ['HAZARD_ON_ROAD_OBJECT',             'en', 'Object on road'],
        ['HAZARD_ON_ROAD_OBJECT',             'es', 'Objeto en la vía'],
        ['HAZARD_ON_ROAD_OIL',                'pt', 'Óleo na pista'],
        ['HAZARD_ON_ROAD_OIL',                'en', 'Oil on road'],
        ['HAZARD_ON_ROAD_OIL',                'es', 'Aceite en la vía'],
        ['HAZARD_ON_ROAD_POT_HOLE',           'pt', 'Buraco na pista'],
        ['HAZARD_ON_ROAD_POT_HOLE',           'en', 'Pot hole'],
        ['HAZARD_ON_ROAD_POT_HOLE',           'es', 'Bache en la vía'],
        ['HAZARD_ON_ROAD_ROAD_KILL',          'pt', 'Animal morto na pista'],
        ['HAZARD_ON_ROAD_ROAD_KILL',          'en', 'Road kill'],
        ['HAZARD_ON_ROAD_ROAD_KILL',          'es', 'Animal atropellado en la vía'],
        ['HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT','pt', 'Semáforo com defeito'],
        ['HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT','en', 'Traffic light fault'],
        ['HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT','es', 'Semáforo averiado'],
        ['HAZARD_ON_SHOULDER',                'pt', 'Perigo no acostamento'],
        ['HAZARD_ON_SHOULDER',                'en', 'Hazard on shoulder'],
        ['HAZARD_ON_SHOULDER',                'es', 'Peligro en el acotamiento'],
        ['HAZARD_ON_SHOULDER_ANIMALS',        'pt', 'Animais no acostamento'],
        ['HAZARD_ON_SHOULDER_ANIMALS',        'en', 'Animals on shoulder'],
        ['HAZARD_ON_SHOULDER_ANIMALS',        'es', 'Animales en el acotamiento'],
        ['HAZARD_ON_SHOULDER_CAR_STOPPED',    'pt', 'Veículo parado no acostamento'],
        ['HAZARD_ON_SHOULDER_CAR_STOPPED',    'en', 'Car stopped on shoulder'],
        ['HAZARD_ON_SHOULDER_CAR_STOPPED',    'es', 'Vehículo detenido en el acotamiento'],
        ['HAZARD_ON_SHOULDER_MISSING_SIGN',   'pt', 'Placa de sinalização ausente'],
        ['HAZARD_ON_SHOULDER_MISSING_SIGN',   'en', 'Missing sign'],
        ['HAZARD_ON_SHOULDER_MISSING_SIGN',   'es', 'Señal de tránsito ausente'],
        ['HAZARD_WEATHER',                    'pt', 'Condição climática'],
        ['HAZARD_WEATHER',                    'en', 'Weather hazard'],
        ['HAZARD_WEATHER',                    'es', 'Peligro climático'],
        ['HAZARD_WEATHER_FLOOD',              'pt', 'Alagamento'],
        ['HAZARD_WEATHER_FLOOD',              'en', 'Flood'],
        ['HAZARD_WEATHER_FLOOD',              'es', 'Inundación'],
        ['HAZARD_WEATHER_FOG',                'pt', 'Neblina'],
        ['HAZARD_WEATHER_FOG',                'en', 'Fog'],
        ['HAZARD_WEATHER_FOG',                'es', 'Niebla'],
        ['HAZARD_WEATHER_FREEZING_RAIN',      'pt', 'Chuva congelante'],
        ['HAZARD_WEATHER_FREEZING_RAIN',      'en', 'Freezing rain'],
        ['HAZARD_WEATHER_FREEZING_RAIN',      'es', 'Lluvia helada'],
        ['HAZARD_WEATHER_HAIL',               'pt', 'Granizo'],
        ['HAZARD_WEATHER_HAIL',               'en', 'Hail'],
        ['HAZARD_WEATHER_HAIL',               'es', 'Granizo'],
        ['HAZARD_WEATHER_HEAT_WAVE',          'pt', 'Onda de calor'],
        ['HAZARD_WEATHER_HEAT_WAVE',          'en', 'Heat wave'],
        ['HAZARD_WEATHER_HEAT_WAVE',          'es', 'Ola de calor'],
        ['HAZARD_WEATHER_HEAVY_RAIN',         'pt', 'Chuva forte'],
        ['HAZARD_WEATHER_HEAVY_RAIN',         'en', 'Heavy rain'],
        ['HAZARD_WEATHER_HEAVY_RAIN',         'es', 'Lluvia fuerte'],
        ['HAZARD_WEATHER_HEAVY_SNOW',         'pt', 'Neve intensa'],
        ['HAZARD_WEATHER_HEAVY_SNOW',         'en', 'Heavy snow'],
        ['HAZARD_WEATHER_HEAVY_SNOW',         'es', 'Nieve intensa'],
        ['HAZARD_WEATHER_HURRICANE',          'pt', 'Furacão'],
        ['HAZARD_WEATHER_HURRICANE',          'en', 'Hurricane'],
        ['HAZARD_WEATHER_HURRICANE',          'es', 'Huracán'],
        ['HAZARD_WEATHER_MONSOON',            'pt', 'Monção'],
        ['HAZARD_WEATHER_MONSOON',            'en', 'Monsoon'],
        ['HAZARD_WEATHER_MONSOON',            'es', 'Monzón'],
        ['HAZARD_WEATHER_TORNADO',            'pt', 'Tornado'],
        ['HAZARD_WEATHER_TORNADO',            'en', 'Tornado'],
        ['HAZARD_WEATHER_TORNADO',            'es', 'Tornado'],
        ['NO_SUBTYPE',                        'pt', 'Sem subtipo específico'],
        ['NO_SUBTYPE',                        'en', 'No specific subtype'],
        ['NO_SUBTYPE',                        'es', 'Sin subtipo específico'],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::DATA as [$type, $subtype, $locale, $label]) {
            $manager->persist((new WazeAlertType())
                ->setType($type)->setSubtype($subtype)->setLocale($locale)->setLabel($label));
        }

        $parentLabels = [
            'HAZARD'        => ['pt' => 'Perigo',          'en' => 'Hazard',         'es' => 'Peligro'],
            'WEATHERHAZARD' => ['pt' => 'Perigo climático', 'en' => 'Weather hazard', 'es' => 'Peligro climático'],
        ];

        foreach ($parentLabels as $type => $labels) {
            foreach ($labels as $locale => $label) {
                $manager->persist((new WazeAlertType())->setType($type)->setSubtype(null)->setLocale($locale)->setLabel($label));
            }
            foreach (self::HAZARD_SUBTYPES as [$subtype, $locale, $label]) {
                $manager->persist((new WazeAlertType())->setType($type)->setSubtype($subtype)->setLocale($locale)->setLabel($label));
            }
        }

        $manager->flush();
    }
}
