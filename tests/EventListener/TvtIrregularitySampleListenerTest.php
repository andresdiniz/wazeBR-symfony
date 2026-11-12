<?php

declare(strict_types=1);
namespace App\Tests\EventListener;

use App\EventListener\TvtIrregularitySampleListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\TestCase;

final class TvtIrregularitySampleListenerTest extends TestCase
{
    public function testDoctrineAttributesCanBeInstantiated(): void
    {
        $reflection = new \ReflectionClass(TvtIrregularitySampleListener::class);
        $attributes = $reflection->getAttributes(AsDoctrineListener::class);
        self::assertCount(2, $attributes);
        foreach ($attributes as $attribute) {
            self::assertArrayNotHasKey('method', $attribute->getArguments());
            self::assertInstanceOf(AsDoctrineListener::class, $attribute->newInstance());
        }
    }

    public function testCallbacksUseDoctrineEventNamesAndTypes(): void
    {
        foreach (['postPersist' => PostPersistEventArgs::class, 'postUpdate' => PostUpdateEventArgs::class] as $method => $eventType) {
            $reflection = new \ReflectionMethod(TvtIrregularitySampleListener::class, $method);
            self::assertTrue($reflection->isPublic());
            self::assertSame($eventType, $reflection->getParameters()[0]->getType()->getName());
        }
    }
}
