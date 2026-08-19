<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Domain;

use Kumwe\App\Extension\Domain\SemanticVersion;
use Kumwe\App\Extension\Domain\VersionConstraint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionConstraint::class)]
final class VersionConstraintTest extends TestCase
{
    public function testEvaluatesRangesAndCaretBoundaries(): void
    {
        $range = VersionConstraint::fromString('>=2.0.0 <3.0.0');

        self::assertTrue($range->accepts(SemanticVersion::fromString('2.9.9')));
        self::assertFalse($range->accepts(SemanticVersion::fromString('3.0.0')));
        self::assertTrue(
            VersionConstraint::fromString('^0.2.3')->accepts(SemanticVersion::fromString('0.2.9')),
        );
        self::assertFalse(
            VersionConstraint::fromString('^0.2.3')->accepts(SemanticVersion::fromString('0.3.0')),
        );
    }

    public function testWildcardAcceptsEveryVersion(): void
    {
        self::assertTrue(VersionConstraint::fromString('*')->accepts(SemanticVersion::fromString('99.0.0')));
    }
}
