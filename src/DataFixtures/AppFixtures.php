<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ActivityLog;
use App\Entity\CemadenData;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // ----------------------------------------------------------------
        // Usuários
        // ----------------------------------------------------------------
        $admin = $this->createUser(
            manager: $manager,
            email:   'admin@wazebr.local',
            name:    'Administrador',
            roles:   ['ROLE_ADMIN'],
            password: 'Admin@12345',
        );

        $user = $this->createUser(
            manager: $manager,
            email:   'usuario@wazebr.local',
            name:    'Usuário Padrão',
            roles:   [],
            password: 'User@12345',
        );

        $manager->flush();

        // ----------------------------------------------------------------
        // Alertas Waze de exemplo
        // ----------------------------------------------------------------
        $alertsData = [
            ['ACCIDENT',    null,           -19.9285, -43.9378, 'Av. Afonso Pena',       'Belo Horizonte', 9, 8],
            ['HAZARD',      'HAZARD_ON_ROAD',  -19.9150, -43.9550, 'R. da Bahia',           'Belo Horizonte', 7, 6],
            ['JAM',         null,           -19.8800, -43.9700, 'Av. Cristóvão Colombo', 'Belo Horizonte', 8, 7],
            ['ROAD_CLOSED', null,           -20.0050, -44.0300, 'BR-040',                'Contagem',        9, 9],
            ['ACCIDENT',    null,           -19.9700, -44.0100, 'Av. Nossa Sra. do Ó',  'Contagem',        6, 5],
            ['HAZARD',      'HAZARD_WEATHER',  -19.9400, -43.8900, 'Rod. MG-10',            'Santa Luzia',     7, 6],
        ];

        foreach ($alertsData as $i => [$type, $subtype, $lat, $lng, $street, $city, $rel, $conf]) {
            $alert = (new WazeAlert())
                ->setWazeId('fixture-alert-' . ($i + 1))
                ->setType($type)
                ->setSubtype($subtype)
                ->setLatitude($lat)
                ->setLongitude($lng)
                ->setStreet($street)
                ->setCity($city)
                ->setCountry('BR')
                ->setReliability($rel)
                ->setConfidence($conf)
                ->setReportRating(rand(3, 5))
                ->setPubMillis((int) (microtime(true) * 1000) - rand(0, 3_600_000));
            $manager->persist($alert);
        }

        // ----------------------------------------------------------------
        // Congestionamentos Waze de exemplo
        // ----------------------------------------------------------------
        $jamsData = [
            ['Av. André Rabiço',      'Belo Horizonte', 4, 12.5, 1200, 300],
            ['Av. Bias Fortes',         'Belo Horizonte', 3, 18.0,  800, 180],
            ['R. Carangola',            'Belo Horizonte', 5,  8.0, 1800, 420],
            ['Av. Raja Gabaglia',       'Belo Horizonte', 2, 25.0,  600,  90],
            ['Rod. Fernao Dias',        'Contagem',       5,  6.5, 2500, 600],
        ];

        foreach ($jamsData as $i => [$street, $city, $level, $speed, $length, $delay]) {
            $jam = (new WazeTrafficJam())
                ->setWazeId('fixture-jam-' . ($i + 1))
                ->setStreet($street)
                ->setCity($city)
                ->setLevel($level)
                ->setSpeedKmh($speed)
                ->setLength((float) $length)
                ->setDelay($delay)
                ->setLine([
                    ['x' => -43.9 - ($i * 0.01), 'y' => -19.93 - ($i * 0.01)],
                    ['x' => -43.9 - ($i * 0.01) + 0.005, 'y' => -19.93 - ($i * 0.01) + 0.005],
                ])
                ->setPubMillis((int) (microtime(true) * 1000) - rand(0, 3_600_000));
            $manager->persist($jam);
        }

        // ----------------------------------------------------------------
        // Estações CEMADEN de exemplo
        // ----------------------------------------------------------------
        $cemadenData = [
            ['31500280',  'Ibirité Centro',         'Ibirité',            'MG', -20.022, -44.058, 45.2,  'VERMELHO'],
            ['31720270',  'Ribeirão das Neves Sul', 'Ribeirão das Neves', 'MG', -19.765, -44.092, 28.5,  'LARANJA'],
            ['31150110',  'BH Centro',              'Belo Horizonte',     'MG', -19.917, -43.934, 12.0,  'AMARELO'],
            ['31660050',  'Sabará Leste',           'Sabará',             'MG', -19.886, -43.804,  3.5,  'VERDE'],
            ['31280050',  'Contagem Norte',         'Contagem',           'MG', -19.900, -44.065, 19.8,  'LARANJA'],
        ];

        foreach ($cemadenData as [$code, $name, $municipality, $state, $lat, $lng, $rain, $alertLevel]) {
            $station = (new CemadenData())
                ->setStationCode($code)
                ->setStationName($name)
                ->setMunicipality($municipality)
                ->setState($state)
                ->setLatitude($lat)
                ->setLongitude($lng)
                ->setAccumulatedRain($rain)
                ->setAlertLevel($alertLevel)
                ->setMeasuredAt(new \DateTimeImmutable('-' . rand(5, 60) . ' minutes'));
            $manager->persist($station);
        }

        $manager->flush();

        // ----------------------------------------------------------------
        // Notificações de exemplo
        // ----------------------------------------------------------------
        $notificationsData = [
            [$admin, 'waze_alert',   'Acidente na Av. Afonso Pena',       'Confiança: 8 | Rua: Av. Afonso Pena, BH'],
            [$admin, 'cemaden',      'Alerta VERMELHO em Ibirité',         'Chuva acumulada: 45.2mm — estação Ibirité Centro'],
            [$user,  'waze_alert',   'Via fechada na BR-040',              'Trânsito bloqueado na BR-040, Contagem'],
            [$user,  'daily_report', 'Relatório diário disponível',        'Acesse o painel para ver o resumo de hoje'],
        ];

        foreach ($notificationsData as [$notifUser, $type, $title, $body]) {
            $notif = (new Notification())
                ->setUser($notifUser)
                ->setType($type)
                ->setTitle($title)
                ->setBody($body);
            $manager->persist($notif);
        }

        // ----------------------------------------------------------------
        // Logs de atividade de exemplo
        // ----------------------------------------------------------------
        $logsData = [
            [$admin, 'login',           'Login realizado com sucesso',     '127.0.0.1'],
            [$admin, 'collect_alerts',  'Coleta de alertas Waze executada', null],
            [$admin, 'collect_traffic', 'Coleta de congestionamentos',      null],
            [$admin, 'collect_cemaden', 'Coleta CEMADEN MG executada',      null],
            [$user,  'login',           'Login realizado com sucesso',      '192.168.0.10'],
        ];

        foreach ($logsData as [$logUser, $action, $desc, $ip]) {
            $log = (new ActivityLog())
                ->setUser($logUser)
                ->setAction($action)
                ->setDescription($desc)
                ->setIpAddress($ip);
            $manager->persist($log);
        }

        $manager->flush();

        echo "\n✅ Fixtures carregadas com sucesso!";
        echo "\n   👤 admin@wazebr.local  / Admin@12345 (ROLE_ADMIN)";
        echo "\n   👤 usuario@wazebr.local / User@12345 (ROLE_USER)";
        echo "\n   📊 6 alertas | 5 jams | 5 estações CEMADEN | 4 notificações\n";
    }

    private function createUser(
        ObjectManager $manager,
        string        $email,
        string        $name,
        array         $roles,
        string        $password,
    ): User {
        $user = (new User())
            ->setEmail($email)
            ->setName($name)
            ->setRoles($roles)
            ->setIsActive(true);

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $manager->persist($user);
        return $user;
    }
}
