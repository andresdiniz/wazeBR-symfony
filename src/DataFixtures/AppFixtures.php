<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ActivityLog;
use App\Entity\CemadenData;
use App\Entity\MonitoredCity;
use App\Entity\MonitoredLink;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Entity\WazeRoute;
use App\Entity\WazeRouteLink;
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
        // Parceiros (tenants)
        // ----------------------------------------------------------------
        $partnerBH = (new Partner())
            ->setName('Prefeitura BH')
            ->setSlug('prefeitura-bh')
            ->setEmail('ti@pbh.gov.br')
            ->setBbox('-20.1,-44.1,-19.8,-43.8')
            ->setCemadenStates(['MG'])
            ->generateApiToken();

        $partnerCT = (new Partner())
            ->setName('Prefeitura Contagem')
            ->setSlug('prefeitura-contagem')
            ->setEmail('ti@contagem.mg.gov.br')
            ->setBbox('-19.95,-44.15,-19.85,-44.0')
            ->setCemadenStates(['MG'])
            ->generateApiToken();

        $manager->persist($partnerBH);
        $manager->persist($partnerCT);
        $manager->flush();

        // ----------------------------------------------------------------
        // Usuários por parceiro
        // ----------------------------------------------------------------
        $adminBH = $this->createUser($manager, 'admin@pbh.gov.br',          'Admin BH',       ['ROLE_ADMIN'], 'Admin@12345',  $partnerBH);
        $userBH  = $this->createUser($manager, 'operador@pbh.gov.br',       'Operador BH',    [],            'User@12345',   $partnerBH);
        $adminCT = $this->createUser($manager, 'admin@contagem.mg.gov.br',  'Admin Contagem', ['ROLE_ADMIN'], 'Admin@12345',  $partnerCT);

        // Super admin global (sem parceiro vinculado)
        $this->createUser($manager, 'superadmin@wazebr.local', 'Super Admin', ['ROLE_SUPER_ADMIN'], 'Super@12345', null);

        $manager->flush();

        // ----------------------------------------------------------------
        // Cidades monitoradas por parceiro
        // ----------------------------------------------------------------
        foreach ([['Belo Horizonte', 'MG'], ['Santa Luzia', 'MG'], ['Contagem', 'MG']] as [$city, $uf]) {
            $manager->persist((new MonitoredCity())->setCity($city)->setState($uf)->setPartner($partnerBH));
        }
        foreach ([['Contagem', 'MG'], ['Ibirité', 'MG']] as [$city, $uf]) {
            $manager->persist((new MonitoredCity())->setCity($city)->setState($uf)->setPartner($partnerCT));
        }

        // ----------------------------------------------------------------
        // Links monitorados por parceiro
        // ----------------------------------------------------------------
        $linksData = [
            [$partnerBH, 'Câmera Afonso Pena',  'https://cameras.pbh.gov.br/afonso-pena',  'camera'],
            [$partnerBH, 'Feed CEMADEN MG',     'https://cemaden.gov.br/feed/MG',           'cemaden'],
            [$partnerCT, 'Câmera BR-040',       'https://cameras.contagem.mg.gov.br/br040', 'camera'],
            [$partnerCT, 'Sensor Av. Raimundo', 'https://sensores.contagem.mg.gov.br/rai',  'sensor'],
        ];
        foreach ($linksData as [$partner, $name, $url, $type]) {
            $manager->persist(
                (new MonitoredLink())->setPartner($partner)->setName($name)->setUrl($url)->setType($type)
            );
        }

        // ----------------------------------------------------------------
        // Rotas e sub-rotas por parceiro
        // ----------------------------------------------------------------
        $routeBH = (new WazeRoute())
            ->setPartner($partnerBH)
            ->setName('Corredor Afonso Pena')
            ->setDescription('Monitoramento do corredor central de BH')
            ->setCoordinates([['lat' => -19.920, 'lng' => -43.938], ['lat' => -19.910, 'lng' => -43.935]]);
        $manager->persist($routeBH);

        $manager->persist(
            (new WazeRouteLink())->setRoute($routeBH)->setName('Trecho Centro → Savassi')
                ->setCoordinates([['lat' => -19.920, 'lng' => -43.938], ['lat' => -19.935, 'lng' => -43.934]])
                ->setSortOrder(1)
        );
        $manager->persist(
            (new WazeRouteLink())->setRoute($routeBH)->setName('Trecho Savassi → Lourdes')
                ->setCoordinates([['lat' => -19.935, 'lng' => -43.934], ['lat' => -19.940, 'lng' => -43.938]])
                ->setSortOrder(2)
        );

        $routeCT = (new WazeRoute())
            ->setPartner($partnerCT)
            ->setName('BR-040 Monitorada')
            ->setDescription('Trecho urbano da BR-040 em Contagem')
            ->setCoordinates([['lat' => -19.960, 'lng' => -44.050], ['lat' => -19.980, 'lng' => -44.060]]);
        $manager->persist($routeCT);

        $manager->persist(
            (new WazeRouteLink())->setRoute($routeCT)->setName('Trecho Entrada → Centro')
                ->setCoordinates([['lat' => -19.960, 'lng' => -44.050], ['lat' => -19.970, 'lng' => -44.055]])
                ->setSortOrder(1)
        );

        // ----------------------------------------------------------------
        // Alertas por parceiro
        // ----------------------------------------------------------------
        $alertsData = [
            [$partnerBH, 'ACCIDENT',    null,             -19.9285, -43.9378, 'Av. Afonso Pena',       'Belo Horizonte', 9, 8],
            [$partnerBH, 'HAZARD',      'HAZARD_ON_ROAD', -19.9150, -43.9550, 'R. da Bahia',           'Belo Horizonte', 7, 6],
            [$partnerBH, 'JAM',         null,             -19.8800, -43.9700, 'Av. Cristóvão Colombo', 'Belo Horizonte', 8, 7],
            [$partnerCT, 'ROAD_CLOSED', null,             -20.0050, -44.0300, 'BR-040',                'Contagem',       9, 9],
            [$partnerCT, 'ACCIDENT',    null,             -19.9700, -44.0100, 'Av. Nossa Sra. do Ó',  'Contagem',       6, 5],
        ];
        foreach ($alertsData as $i => [$partner, $type, $subtype, $lat, $lng, $street, $city, $rel, $conf]) {
            $manager->persist(
                (new WazeAlert())
                    ->setPartner($partner)
                    ->setWazeId('fix-alert-' . ($i + 1))
                    ->setType($type)->setSubtype($subtype)
                    ->setLatitude($lat)->setLongitude($lng)
                    ->setStreet($street)->setCity($city)->setCountry('BR')
                    ->setReliability($rel)->setConfidence($conf)
                    ->setReportRating(rand(3, 5))
                    ->setPubMillis((int)(microtime(true) * 1000) - rand(0, 3_600_000))
            );
        }

        // ----------------------------------------------------------------
        // Congestionamentos por parceiro
        // ----------------------------------------------------------------
        $jamsData = [
            [$partnerBH, 'Av. André Rabição', 'Belo Horizonte', 4, 12.5, 1200, 300],
            [$partnerBH, 'Av. Bias Fortes',   'Belo Horizonte', 3, 18.0,  800, 180],
            [$partnerCT, 'Rod. Fernão Dias',  'Contagem',       5,  6.5, 2500, 600],
        ];
        foreach ($jamsData as $i => [$partner, $street, $city, $level, $speed, $length, $delay]) {
            $manager->persist(
                (new WazeTrafficJam())
                    ->setPartner($partner)
                    ->setWazeId('fix-jam-' . ($i + 1))
                    ->setStreet($street)->setCity($city)
                    ->setLevel($level)->setSpeedKmh($speed)
                    ->setLength((float)$length)->setDelay($delay)
                    ->setLine([['x' => -43.9 - ($i * 0.01), 'y' => -19.93 - ($i * 0.01)]])
                    ->setPubMillis((int)(microtime(true) * 1000) - rand(0, 3_600_000))
            );
        }

        // ----------------------------------------------------------------
        // CEMADEN por parceiro
        // ----------------------------------------------------------------
        $cemadenData = [
            [$partnerBH, '31500280', 'Ibirité Centro', 'Ibirité',         'MG', -20.022, -44.058, 45.2, 'VERMELHO'],
            [$partnerBH, '31150110', 'BH Centro',      'Belo Horizonte', 'MG', -19.917, -43.934, 12.0, 'AMARELO'],
            [$partnerCT, '31280050', 'Contagem Norte', 'Contagem',        'MG', -19.900, -44.065, 19.8, 'LARANJA'],
        ];
        foreach ($cemadenData as [$partner, $code, $name, $municipality, $state, $lat, $lng, $rain, $level]) {
            $manager->persist(
                (new CemadenData())
                    ->setPartner($partner)
                    ->setStationCode($code)->setStationName($name)
                    ->setMunicipality($municipality)->setState($state)
                    ->setLatitude($lat)->setLongitude($lng)
                    ->setAccumulatedRain($rain)->setAlertLevel($level)
                    ->setMeasuredAt(new \DateTimeImmutable('-' . rand(5, 60) . ' minutes'))
            );
        }

        $manager->flush();

        // ----------------------------------------------------------------
        // Notificações e logs
        // ----------------------------------------------------------------
        $manager->persist(
            (new Notification())
                ->setUser($adminBH)->setPartner($partnerBH)
                ->setType('waze_alert')
                ->setTitle('Acidente na Av. Afonso Pena')
                ->setBody('Confiança: 8 | BH')
        );
        $manager->persist(
            (new Notification())
                ->setUser($adminCT)->setPartner($partnerCT)
                ->setType('cemaden')
                ->setTitle('Alerta LARANJA em Contagem')
                ->setBody('Chuva: 19.8mm — Estação Contagem Norte')
        );
        $manager->persist(
            (new ActivityLog())
                ->setUser($adminBH)->setPartner($partnerBH)
                ->setAction('login')->setDescription('Login realizado')->setIpAddress('127.0.0.1')
        );
        $manager->persist(
            (new ActivityLog())
                ->setUser($userBH)->setPartner($partnerBH)
                ->setAction('collect_alerts')->setDescription('Coleta automática de alertas Waze')
        );

        $manager->flush();

        echo "\n✅ Fixtures multi-tenant carregadas com sucesso!\n";
        echo "\n🏢 Parceiro: Prefeitura BH";
        echo "\n   Token API : {$partnerBH->getApiToken()}";
        echo "\n   admin@pbh.gov.br     / Admin@12345";
        echo "\n   operador@pbh.gov.br  / User@12345";
        echo "\n\n🏢 Parceiro: Prefeitura Contagem";
        echo "\n   Token API : {$partnerCT->getApiToken()}";
        echo "\n   admin@contagem.mg.gov.br / Admin@12345";
        echo "\n\n🔑 Super Admin global";
        echo "\n   superadmin@wazebr.local / Super@12345 (ROLE_SUPER_ADMIN)\n\n";
    }

    private function createUser(
        ObjectManager $manager,
        string        $email,
        string        $name,
        array         $roles,
        string        $password,
        ?Partner      $partner,
    ): User {
        $user = (new User())
            ->setEmail($email)
            ->setName($name)
            ->setRoles($roles)
            ->setIsActive(true);

        if ($partner !== null) {
            $user->setPartner($partner);
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $manager->persist($user);
        return $user;
    }
}
