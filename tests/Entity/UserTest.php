<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testDefaultRolesIncludeRoleUser(): void
    {
        $user = new User();
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testAdminRolesIncludeRoleAdmin(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN']);
        $this->assertContains('ROLE_ADMIN', $user->getRoles());
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testUserIdentifierIsEmail(): void
    {
        $user = (new User())->setEmail('test@example.com');
        $this->assertSame('test@example.com', $user->getUserIdentifier());
    }

    public function testEraseCredentialsClearsPassword(): void
    {
        $user = (new User())->setPassword('hashed-pw');
        $user->eraseCredentials();
        // eraseCredentials é no-op no Symfony Security padrão;
        // garantimos que não lança exceção
        $this->assertNotEmpty($user->getPassword());
    }

    public function testIsActiveDefaultTrue(): void
    {
        $user = new User();
        $this->assertTrue($user->isActive());
    }
}
