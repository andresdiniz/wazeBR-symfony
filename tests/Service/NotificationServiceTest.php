<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotificationServiceTest extends TestCase
{
    private NotificationRepository&MockObject $notifRepo;
    private UserRepository&MockObject $userRepo;
    private LoggerInterface&MockObject $logger;
    private NotificationService $service;

    protected function setUp(): void
    {
        $this->notifRepo = $this->createMock(NotificationRepository::class);
        $this->userRepo  = $this->createMock(UserRepository::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->service = new NotificationService(
            notificationRepository: $this->notifRepo,
            userRepository: $this->userRepo,
            logger: $this->logger,
        );
    }

    public function testNotifyAllActiveUsersCreatesNotifications(): void
    {
        $user1 = (new User())->setEmail('a@test.com')->setName('A');
        $user2 = (new User())->setEmail('b@test.com')->setName('B');

        $this->userRepo->expects($this->once())
            ->method('findActiveUsers')
            ->willReturn([$user1, $user2]);

        $this->notifRepo->expects($this->exactly(2))
            ->method('save');

        $count = $this->service->notifyAllActiveUsers(
            type: 'test_alert',
            title: 'Teste',
            body: 'Corpo da notificação',
        );

        $this->assertSame(2, $count);
    }

    public function testMarkAsReadUpdatesNotification(): void
    {
        $notif = new Notification();
        $notif->setIsRead(false);

        $this->notifRepo->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($notif);

        $this->notifRepo->expects($this->once())
            ->method('save');

        $this->service->markAsRead(42);

        $this->assertTrue($notif->isRead());
    }

    public function testMarkAsReadIgnoresMissingNotification(): void
    {
        $this->notifRepo->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->notifRepo->expects($this->never())
            ->method('save');

        $this->service->markAsRead(999);
        $this->addToAssertionCount(1);
    }
}
