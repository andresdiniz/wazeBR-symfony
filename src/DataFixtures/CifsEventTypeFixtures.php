<?php

namespace App\DataFixtures;

use App\Entity\CifsEventType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CifsEventTypeFixtures extends Fixture
{
    /**
     * Dados: [type, subtype|null, locale, label, description|null]
     * Inclui pt, en e es para cada entrada.
     */
    private const DATA = [
        // ── ROAD_CLOSED ──────────────────────────────────────────
        ['ROAD_CLOSED', null, 'pt', 'Via Interditada',              'Bloqueio total da via'],
        ['ROAD_CLOSED', null, 'en', 'Road Closed',                  'Full road closure'],
        ['ROAD_CLOSED', null, 'es', 'Vía Cerrada',                  'Cierre total de la vía'],

        ['ROAD_CLOSED', 'ROAD_CLOSED_HAZARD',       'pt', 'Interditada por perigo',    null],
        ['ROAD_CLOSED', 'ROAD_CLOSED_HAZARD',       'en', 'Closed due to hazard',      null],
        ['ROAD_CLOSED', 'ROAD_CLOSED_HAZARD',       'es', 'Cerrada por peligro',       null],

        ['ROAD_CLOSED', 'ROAD_CLOSED_CONSTRUCTION', 'pt', 'Interditada por obra',      null],
        ['ROAD_CLOSED', 'ROAD_CLOSED_CONSTRUCTION', 'en', 'Closed for construction',   null],
        ['ROAD_CLOSED', 'ROAD_CLOSED_CONSTRUCTION', 'es', 'Cerrada por obras',         null],

        ['ROAD_CLOSED', 'ROAD_CLOSED_EVENT',        'pt', 'Interditada por evento',    null],
        ['ROAD_CLOSED', 'ROAD_CLOSED_EVENT',        'en', 'Closed for event',          null],
        ['ROAD_CLOSED', 'ROAD_CLOSED_EVENT',        'es', 'Cerrada por evento',        null],

        // ── ACCIDENT ─────────────────────────────────────────────
        ['ACCIDENT', null, 'pt', 'Acidente',        null],
        ['ACCIDENT', null, 'en', 'Accident',        null],
        ['ACCIDENT', null, 'es', 'Accidente',       null],

        ['ACCIDENT', 'ACCIDENT_MINOR', 'pt', 'Acidente leve',     null],
        ['ACCIDENT', 'ACCIDENT_MINOR', 'en', 'Minor accident',    null],
        ['ACCIDENT', 'ACCIDENT_MINOR', 'es', 'Accidente menor',   null],

        ['ACCIDENT', 'ACCIDENT_MAJOR', 'pt', 'Acidente grave',    null],
        ['ACCIDENT', 'ACCIDENT_MAJOR', 'en', 'Major accident',    null],
        ['ACCIDENT', 'ACCIDENT_MAJOR', 'es', 'Accidente grave',   null],

        // ── HAZARD ───────────────────────────────────────────────
        ['HAZARD', null, 'pt', 'Perigo',   null],
        ['HAZARD', null, 'en', 'Hazard',   null],
        ['HAZARD', null, 'es', 'Peligro',  null],

        ['HAZARD', 'HAZARD_ON_ROAD',                    'pt', 'Perigo na pista',             null],
        ['HAZARD', 'HAZARD_ON_ROAD',                    'en', 'Hazard on road',               null],
        ['HAZARD', 'HAZARD_ON_ROAD',                    'es', 'Peligro en la vía',            null],

        ['HAZARD', 'HAZARD_ON_ROAD_CAR_STOPPED',        'pt', 'Carro parado na pista',       null],
        ['HAZARD', 'HAZARD_ON_ROAD_CAR_STOPPED',        'en', 'Car stopped on road',         null],
        ['HAZARD', 'HAZARD_ON_ROAD_CAR_STOPPED',        'es', 'Auto detenido en vía',        null],

        ['HAZARD', 'HAZARD_ON_ROAD_CONSTRUCTION',       'pt', 'Obra na pista',               null],
        ['HAZARD', 'HAZARD_ON_ROAD_CONSTRUCTION',       'en', 'Construction on road',        null],
        ['HAZARD', 'HAZARD_ON_ROAD_CONSTRUCTION',       'es', 'Obra en la vía',              null],

        ['HAZARD', 'HAZARD_ON_ROAD_EMERGENCY_VEHICLE',  'pt', 'Veículo de emergência',       null],
        ['HAZARD', 'HAZARD_ON_ROAD_EMERGENCY_VEHICLE',  'en', 'Emergency vehicle',           null],
        ['HAZARD', 'HAZARD_ON_ROAD_EMERGENCY_VEHICLE',  'es', 'Vehículo de emergencia',      null],

        ['HAZARD', 'HAZARD_ON_ROAD_ICE',                'pt', 'Pista com gelo',              null],
        ['HAZARD', 'HAZARD_ON_ROAD_ICE',                'en', 'Ice on road',                 null],
        ['HAZARD', 'HAZARD_ON_ROAD_ICE',                'es', 'Hielo en la vía',             null],

        ['HAZARD', 'HAZARD_ON_ROAD_LANE_CLOSED',        'pt', 'Faixa fechada',               null],
        ['HAZARD', 'HAZARD_ON_ROAD_LANE_CLOSED',        'en', 'Lane closed',                 null],
        ['HAZARD', 'HAZARD_ON_ROAD_LANE_CLOSED',        'es', 'Carril cerrado',              null],

        ['HAZARD', 'HAZARD_ON_ROAD_OBJECT',             'pt', 'Objeto na pista',             null],
        ['HAZARD', 'HAZARD_ON_ROAD_OBJECT',             'en', 'Object on road',              null],
        ['HAZARD', 'HAZARD_ON_ROAD_OBJECT',             'es', 'Objeto en la vía',            null],

        ['HAZARD', 'HAZARD_ON_ROAD_OIL',                'pt', 'Óleo / combustível derramado', null],
        ['HAZARD', 'HAZARD_ON_ROAD_OIL',                'en', 'Oil spill on road',           null],
        ['HAZARD', 'HAZARD_ON_ROAD_OIL',                'es', 'Derrame de aceite',           null],

        ['HAZARD', 'HAZARD_ON_ROAD_POT_HOLE',           'pt', 'Buraco na pista',             null],
        ['HAZARD', 'HAZARD_ON_ROAD_POT_HOLE',           'en', 'Pothole',                     null],
        ['HAZARD', 'HAZARD_ON_ROAD_POT_HOLE',           'es', 'Bache',                       null],

        ['HAZARD', 'HAZARD_ON_ROAD_ROAD_KILL',          'pt', 'Animal morto na pista',       null],
        ['HAZARD', 'HAZARD_ON_ROAD_ROAD_KILL',          'en', 'Road kill',                   null],
        ['HAZARD', 'HAZARD_ON_ROAD_ROAD_KILL',          'es', 'Animal muerto en vía',        null],

        ['HAZARD', 'HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT','pt', 'Semáforo com defeito',        null],
        ['HAZARD', 'HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT','en', 'Traffic light fault',         null],
        ['HAZARD', 'HAZARD_ON_ROAD_TRAFFIC_LIGHT_FAULT','es', 'Semáforo averiado',           null],

        ['HAZARD', 'HAZARD_ON_SHOULDER',                'pt', 'Perigo no acostamento',       null],
        ['HAZARD', 'HAZARD_ON_SHOULDER',                'en', 'Hazard on shoulder',          null],
        ['HAZARD', 'HAZARD_ON_SHOULDER',                'es', 'Peligro en el arcén',         null],

        ['HAZARD', 'HAZARD_ON_SHOULDER_ANIMALS',        'pt', 'Animais no acostamento',      null],
        ['HAZARD', 'HAZARD_ON_SHOULDER_ANIMALS',        'en', 'Animals on shoulder',         null],
        ['HAZARD', 'HAZARD_ON_SHOULDER_ANIMALS',        'es', 'Animales en el arcén',        null],

        ['HAZARD', 'HAZARD_ON_SHOULDER_CAR_STOPPED',    'pt', 'Carro parado no acostamento', null],
        ['HAZARD', 'HAZARD_ON_SHOULDER_CAR_STOPPED',    'en', 'Car stopped on shoulder',     null],
        ['HAZARD', 'HAZARD_ON_SHOULDER_CAR_STOPPED',    'es', 'Auto detenido en el arcén',   null],

        ['HAZARD', 'HAZARD_ON_SHOULDER_MISSING_SIGN',   'pt', 'Placa faltando',              null],
        ['HAZARD', 'HAZARD_ON_SHOULDER_MISSING_SIGN',   'en', 'Missing sign',                null],
        ['HAZARD', 'HAZARD_ON_SHOULDER_MISSING_SIGN',   'es', 'Señal faltante',              null],

        ['HAZARD', 'HAZARD_WEATHER',                    'pt', 'Condição climática',          null],
        ['HAZARD', 'HAZARD_WEATHER',                    'en', 'Weather hazard',              null],
        ['HAZARD', 'HAZARD_WEATHER',                    'es', 'Peligro climático',           null],

        ['HAZARD', 'HAZARD_WEATHER_FLOOD',              'pt', 'Alagamento / Enchente',       null],
        ['HAZARD', 'HAZARD_WEATHER_FLOOD',              'en', 'Flooding',                    null],
        ['HAZARD', 'HAZARD_WEATHER_FLOOD',              'es', 'Inundación',                  null],

        ['HAZARD', 'HAZARD_WEATHER_FOG',                'pt', 'Neblina',                     null],
        ['HAZARD', 'HAZARD_WEATHER_FOG',                'en', 'Fog',                         null],
        ['HAZARD', 'HAZARD_WEATHER_FOG',                'es', 'Niebla',                      null],

        ['HAZARD', 'HAZARD_WEATHER_FREEZING_RAIN',      'pt', 'Chuva congelante',            null],
        ['HAZARD', 'HAZARD_WEATHER_FREEZING_RAIN',      'en', 'Freezing rain',               null],
        ['HAZARD', 'HAZARD_WEATHER_FREEZING_RAIN',      'es', 'Lluvia helada',               null],

        ['HAZARD', 'HAZARD_WEATHER_HAIL',               'pt', 'Granizo',                     null],
        ['HAZARD', 'HAZARD_WEATHER_HAIL',               'en', 'Hail',                        null],
        ['HAZARD', 'HAZARD_WEATHER_HAIL',               'es', 'Granizo',                     null],

        ['HAZARD', 'HAZARD_WEATHER_HEAVY_RAIN',         'pt', 'Chuva forte',                 null],
        ['HAZARD', 'HAZARD_WEATHER_HEAVY_RAIN',         'en', 'Heavy rain',                  null],
        ['HAZARD', 'HAZARD_WEATHER_HEAVY_RAIN',         'es', 'Lluvia intensa',              null],

        ['HAZARD', 'HAZARD_WEATHER_HURRICANE',          'pt', 'Furacão',                     null],
        ['HAZARD', 'HAZARD_WEATHER_HURRICANE',          'en', 'Hurricane',                   null],
        ['HAZARD', 'HAZARD_WEATHER_HURRICANE',          'es', 'Huracán',                     null],

        ['HAZARD', 'HAZARD_WEATHER_TORNADO',            'pt', 'Tornado',                     null],
        ['HAZARD', 'HAZARD_WEATHER_TORNADO',            'en', 'Tornado',                     null],
        ['HAZARD', 'HAZARD_WEATHER_TORNADO',            'es', 'Tornado',                     null],

        // ── JAM ──────────────────────────────────────────────────
        ['JAM', null, 'pt', 'Congestionamento',   null],
        ['JAM', null, 'en', 'Traffic Jam',        null],
        ['JAM', null, 'es', 'Embotellamiento',    null],

        ['JAM', 'JAM_LIGHT_TRAFFIC',        'pt', 'Tráfego leve',          null],
        ['JAM', 'JAM_LIGHT_TRAFFIC',        'en', 'Light traffic',         null],
        ['JAM', 'JAM_LIGHT_TRAFFIC',        'es', 'Tráfico ligero',        null],

        ['JAM', 'JAM_MODERATE_TRAFFIC',     'pt', 'Tráfego moderado',      null],
        ['JAM', 'JAM_MODERATE_TRAFFIC',     'en', 'Moderate traffic',      null],
        ['JAM', 'JAM_MODERATE_TRAFFIC',     'es', 'Tráfico moderado',      null],

        ['JAM', 'JAM_HEAVY_TRAFFIC',        'pt', 'Tráfego intenso',       null],
        ['JAM', 'JAM_HEAVY_TRAFFIC',        'en', 'Heavy traffic',         null],
        ['JAM', 'JAM_HEAVY_TRAFFIC',        'es', 'Tráfico intenso',       null],

        ['JAM', 'JAM_STAND_STILL_TRAFFIC',  'pt', 'Trânsito parado',       null],
        ['JAM', 'JAM_STAND_STILL_TRAFFIC',  'en', 'Stand-still traffic',   null],
        ['JAM', 'JAM_STAND_STILL_TRAFFIC',  'es', 'Tráfico detenido',      null],

        // ── POLICE ───────────────────────────────────────────────
        ['POLICE', null, 'pt', 'Polícia',  null],
        ['POLICE', null, 'en', 'Police',   null],
        ['POLICE', null, 'es', 'Policía',  null],

        ['POLICE', 'POLICE_VISIBLE',  'pt', 'Polícia visível',   null],
        ['POLICE', 'POLICE_VISIBLE',  'en', 'Police visible',    null],
        ['POLICE', 'POLICE_VISIBLE',  'es', 'Policía visible',   null],

        ['POLICE', 'POLICE_HIDING',   'pt', 'Blitz / Radar',     null],
        ['POLICE', 'POLICE_HIDING',   'en', 'Police hiding',     null],
        ['POLICE', 'POLICE_HIDING',   'es', 'Policía oculta',    null],

        // ── CHIT_CHAT ─────────────────────────────────────────────
        ['CHIT_CHAT', null, 'pt', 'Informação',   null],
        ['CHIT_CHAT', null, 'en', 'Information',  null],
        ['CHIT_CHAT', null, 'es', 'Información',  null],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::DATA as [$type, $subtype, $locale, $label, $description]) {
            // Evita duplicatas em re-execuções
            $existing = $manager->getRepository(CifsEventType::class)->findOneBy([
                'type'    => $type,
                'subtype' => $subtype,
                'locale'  => $locale,
            ]);
            if ($existing) continue;

            $entry = new CifsEventType();
            $entry->setType($type);
            $entry->setSubtype($subtype);
            $entry->setLocale($locale);
            $entry->setLabel($label);
            $entry->setDescription($description);
            $manager->persist($entry);
        }

        $manager->flush();
    }
}
