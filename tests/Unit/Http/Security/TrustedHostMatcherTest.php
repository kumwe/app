<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Http\Security;

use InvalidArgumentException;
use Kumwe\CMS\Http\Security\TrustedHostMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
