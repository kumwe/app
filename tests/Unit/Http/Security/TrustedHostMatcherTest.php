<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Http\Security;

use InvalidArgumentException;
use Kumwe\CMS\Http\Security\TrustedHostMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrustedHostMatcher::class)]
final class TrustedHostMatcherTest extends TestCase
{
    public function testMatchesExactHostWithPort(): void
    {
        $matcher = new TrustedHostMatcher(['kumwe.test']);

        self::assertTrue($matcher->matches('KUMWE.TEST:8443'));
        self::assertFalse($matcher->matches('attacker.test'));
    }

    public function testWildcardMatchesOnlySubdomains(): void
    {
        $matcher = new TrustedHostMatcher(['*.kumwe.test']);

        self::assertTrue($matcher->matches('admin.kumwe.test'));
        self::assertFalse($matcher->matches('kumwe.test'));
        self::assertFalse($matcher->matches('notkumwe.test'));
    }

    public function testMalformedHostIsRejected(): void
    {
        $matcher = new TrustedHostMatcher(['kumwe.test']);
        $this->expectException(InvalidArgumentException::class);

        $matcher->matches('kumwe.test@attacker.test');
    }

    #[DataProvider('malformedAuthorityProvider')]
    public function testMalformedAuthoritySuffixIsRejected(string $authority): void
    {
        $matcher = new TrustedHostMatcher(['kumwe.test', '::1']);
        $this->expectException(InvalidArgumentException::class);

        $matcher->matches($authority);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAuthorityProvider(): iterable
    {
        yield 'named port' => ['kumwe.test:attacker'];
        yield 'empty port' => ['kumwe.test:'];
        yield 'port above range' => ['kumwe.test:65536'];
        yield 'IPv6 trailing text' => ['[::1]attacker'];
        yield 'IPv6 invalid port' => ['[::1]:tls'];
        yield 'unbracketed IPv6' => ['::1'];
    }
}
