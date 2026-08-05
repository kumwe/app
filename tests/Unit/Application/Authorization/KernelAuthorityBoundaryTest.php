<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Authorization;

use Kumwe\CMS\Kernel\ContainerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ContainerFactory::class)]
final class KernelAuthorityBoundaryTest extends TestCase
{
    public function testProductionCompositionAcceptsNoCallerSuppliedAuthority(): void
    {
        $factory = new ReflectionClass(ContainerFactory::class);

        self::assertNull($factory->getConstructor());
        self::assertCount(1, $factory->getMethod('create')->getParameters());
        self::assertFalse($factory->hasMethod('createWithProvenance'));
        foreach ($factory->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                self::assertNotSame('provenance', $parameter->getName());
            }
        }
        self::assertFalse(class_exists('Kumwe\\CMS\\Application\\Authorization\\AuthorizationAuthority'));
    }
}
