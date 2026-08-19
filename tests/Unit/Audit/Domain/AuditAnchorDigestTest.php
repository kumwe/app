<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Audit\Domain;

use Kumwe\App\Audit\Domain\AuditAnchorDigest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditAnchorDigest::class)]
final class AuditAnchorDigestTest extends TestCase
{
    private const string ALPHA = 'aa11bb22cc33dd44ee55ff6600112233445566778899aabbccddeeff0011223344';

    public function testRollingDigestBindsBothTheDigestsAndTheirPositions(): void
    {
        $intact = AuditAnchorDigest::rolling([1 => $this->digest('a'), 2 => $this->digest('b')]);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $intact);
        self::assertSame($intact, AuditAnchorDigest::rolling([1 => $this->digest('a'), 2 => $this->digest('b')]));
        self::assertNotSame(
            $intact,
            AuditAnchorDigest::rolling([1 => $this->digest('b'), 2 => $this->digest('a')]),
            'Swapping two rows must change the anchored rolling digest.',
        );
        self::assertNotSame(
            $intact,
            AuditAnchorDigest::rolling([1 => $this->digest('a'), 3 => $this->digest('b')]),
            'Moving a row to another position must change the anchored rolling digest.',
        );
        self::assertNotSame(
            $intact,
            AuditAnchorDigest::rolling([1 => $this->digest('a')]),
            'Removing a row must change the anchored rolling digest.',
        );
        self::assertNotSame(
            $intact,
            AuditAnchorDigest::rolling([1 => $this->digest('a'), 2 => $this->digest('b'), 3 => $this->digest('c')]),
            'Inserting a row must change the anchored rolling digest.',
        );
    }

    public function testAnchorDigestCoversEveryChainedField(): void
    {
        $baseline = AuditAnchorDigest::compute(2, 'anchor', 11, 20, 10, self::ALPHA, null, null, '2026-08-13 09:00:00');

        self::assertSame(
            $baseline,
            AuditAnchorDigest::compute(2, 'anchor', 11, 20, 10, self::ALPHA, null, null, '2026-08-13 09:00:00'),
        );
        foreach (
            [
            AuditAnchorDigest::compute(3, 'anchor', 11, 20, 10, self::ALPHA, null, null, '2026-08-13 09:00:00'),
            AuditAnchorDigest::compute(2, 'prune', 11, 20, 10, self::ALPHA, null, null, '2026-08-13 09:00:00'),
            AuditAnchorDigest::compute(2, 'anchor', 12, 20, 10, self::ALPHA, null, null, '2026-08-13 09:00:00'),
            AuditAnchorDigest::compute(2, 'anchor', 11, 21, 10, self::ALPHA, null, null, '2026-08-13 09:00:00'),
            AuditAnchorDigest::compute(2, 'anchor', 11, 20, 9, self::ALPHA, null, null, '2026-08-13 09:00:00'),
            AuditAnchorDigest::compute(2, 'anchor', 11, 20, 10, $this->digest('z'), null, null, '2026-08-13 09:00:00'),
            AuditAnchorDigest::compute(2, 'anchor', 11, 20, 10, self::ALPHA, self::ALPHA, null, '2026-08-13 09:00:00'),
            AuditAnchorDigest::compute(2, 'anchor', 11, 20, 10, self::ALPHA, null, self::ALPHA, '2026-08-13 09:00:00'),
            AuditAnchorDigest::compute(2, 'anchor', 11, 20, 10, self::ALPHA, null, null, '2026-08-13 09:00:01'),
            ] as $variant
        ) {
            self::assertNotSame($baseline, $variant);
        }
    }

    private function digest(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
