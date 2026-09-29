<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\TrafficLight;

use App\Entity\Partner;
use App\Entity\TrafficLight;
use App\Service\TrafficLight\TrafficLightManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TrafficLightManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TrafficLightManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->manager = $container->get(TrafficLightManager::class);
    }

    public function testPollPersistsSnapshotAndUpdatesStatus(): void
    {
        $partner = (new Partner())
            ->setName('Teste')
            ->setCode('tst-' . uniqid());

        $light = (new TrafficLight())
            ->setPartner($partner)
            ->setCode('FAKE-' . uniqid())
            ->setName('Fake')
            ->setProtocol(TrafficLight::PROTOCOL_FAKE)
            ->setEndpoint('fake://local')
            ->setOptions([]);

        $this->em->persist($partner);
        $this->em->persist($light);
        $this->em->flush();

        $snapshot = $this->manager->poll($light);

        self::assertTrue($snapshot->isSuccess());
        self::assertNotNull($snapshot->getId());
        self::assertSame(TrafficLight::STATUS_OK, $light->getLastReadStatus());
        self::assertNotNull($light->getLastReadAt());
        self::assertSame(1, $snapshot->getCurrentPhase());
    }

    public function testPollMarksErrorWhenConnectorFails(): void
    {
        $partner = (new Partner())
            ->setName('Teste')
            ->setCode('tst-' . uniqid());

        $light = (new TrafficLight())
            ->setPartner($partner)
            ->setCode('BAD-' . uniqid())
            ->setName('Bad')
            ->setProtocol(TrafficLight::PROTOCOL_MODBUS_TCP)
            ->setEndpoint('127.0.0.1:1')
            ->setOptions(['timeout' => 0.5]);

        $this->em->persist($partner);
        $this->em->persist($light);
        $this->em->flush();

        $snapshot = $this->manager->poll($light);

        self::assertFalse($snapshot->isSuccess());
        self::assertSame(TrafficLight::STATUS_ERROR, $light->getLastReadStatus());
        self::assertNotNull($light->getLastReadError());
    }
}
