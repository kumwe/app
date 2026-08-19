<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Domain;

use Kumwe\App\Extension\Domain\ExtensionDependency;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Extension\Domain\SemanticVersion;
use Kumwe\App\Extension\Domain\VersionConstraint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionDependency::class)]
final class ExtensionDependencyTest extends TestCase
{
    public function testEvaluatesItsCompatibilityAndOptionality(): void
    {
        $dependency = new ExtensionDependency(
            ExtensionIdentifier::fromString('acme/library'),
            VersionConstraint::fromString('^1.2.0'),
            true,
        );

        self::assertTrue($dependency->isOptional());
        self::assertTrue($dependency->isSatisfiedBy(SemanticVersion::fromString('1.9.0')));
        self::assertFalse($dependency->isSatisfiedBy(SemanticVersion::fromString('2.0.0')));
    }
}
