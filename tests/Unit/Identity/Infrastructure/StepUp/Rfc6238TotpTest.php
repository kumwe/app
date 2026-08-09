<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Infrastructure\StepUp;

use DateTimeImmutable;
use DateTimeZone;
use Kumwe\CMS\Identity\Infrastructure\StepUp\Rfc6238Totp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rfc6238Totp::class)]
final class Rfc6238TotpTest extends TestCase
{
    #[DataProvider('rfcVectors')]
    public function testMatchesTheRfc6238Sha1Vectors(int $timestamp, string $code): void
    {
        $totp = new Rfc6238Totp(period: 30, digits: 8, window: 0, algorithm: 'sha1');
        $now = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));

        self::assertSame(intdiv($timestamp, 30), $totp->verify('12345678901234567890', $code, $now));
        self::assertNull($totp->verify('12345678901234567890', '00000000', $now));
    }

    /**
     * @return list<array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            [59, '94287082'],
            [1_111_111_109, '07081804'],
            [1_111_111_111, '14050471'],
            [1_234_567_890, '89005924'],
            [2_000_000_000, '69279037'],
        ];
    }

    public function testBase32EncodingIsUnpaddedAndStandard(): void
    {
        self::assertSame('MZXW6', (new Rfc6238Totp())->encodeSecret('foo'));
    }
}
