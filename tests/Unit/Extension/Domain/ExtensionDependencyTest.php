<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Domain;

use Kumwe\CMS\Extension\Domain\ExtensionDependency;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\SemanticVersion;
use Kumwe\CMS\Extension\Domain\VersionConstraint;
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
