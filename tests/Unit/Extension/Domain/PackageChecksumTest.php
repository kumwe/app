<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Domain;

use InvalidArgumentException;
use Kumwe\App\Extension\Domain\PackageChecksum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageChecksum::class)]
final class PackageChecksumTest extends TestCase
{
    public function testCalculatesAndVerifiesSha256(): void
    {
        $checksum = PackageChecksum::calculate('package bytes');

        self::assertTrue($checksum->matches('package bytes'));
        self::assertFalse($checksum->matches('tampered'));
        self::assertSame(hash('sha256', 'package bytes'), (string) $checksum);
    }

    public function testRejectsMalformedDigests(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PackageChecksum::sha256('not-a-digest');
    }
}
