<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Security;

use InvalidArgumentException;
use Kumwe\App\Http\Security\TrustedProxyMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrustedProxyMatcher::class)]
final class TrustedProxyMatcherTest extends TestCase
{
    public function testMatchesIndividualAddressesAndCidrRangesAcrossBothAddressFamilies(): void
    {
        $matcher = new TrustedProxyMatcher([
            '192.0.2.10',
            '10.20.0.0/16',
            '2001:db8::1',
            'fd00:1234::/48',
        ]);

        self::assertTrue($matcher->matches('192.0.2.10'));
        self::assertFalse($matcher->matches('192.0.2.11'));
        self::assertTrue($matcher->matches('10.20.255.254'));
        self::assertFalse($matcher->matches('10.21.0.1'));
        self::assertTrue($matcher->matches('2001:db8::1'));
        self::assertFalse($matcher->matches('2001:db8::2'));
        self::assertTrue($matcher->matches('fd00:1234:0:ffff::5'));
        self::assertFalse($matcher->matches('fd00:1235::5'));
        self::assertFalse($matcher->matches('not-an-address'));
    }

    #[DataProvider('invalidRangeProvider')]
    public function testRejectsInvalidConfiguration(string $range): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrustedProxyMatcher([$range]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRangeProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'not an IP address' => ['proxy.internal'];
        yield 'invalid IPv4 prefix' => ['192.0.2.1/33'];
        yield 'invalid IPv6 prefix' => ['2001:db8::1/129'];
        yield 'negative prefix' => ['192.0.2.1/-1'];
        yield 'non-numeric prefix' => ['192.0.2.1/network'];
        yield 'multiple separators' => ['192.0.2.1/24/1'];
    }
}
