<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Http\Security;

use Kumwe\CMS\Http\Security\SecurityHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityHeaders::class)]
final class SecurityHeadersTest extends TestCase
{
    public function testProvidesHardenedHeadersAndNonce(): void
    {
        $headers = (new SecurityHeaders(true))->values('safe-nonce');

        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertStringContainsString("script-src 'self' 'nonce-safe-nonce'", $headers['Content-Security-Policy']);
        self::assertArrayHasKey('Strict-Transport-Security', $headers);
    }

    public function testDoesNotEmitHstsForUnverifiedHttp(): void
    {
        self::assertArrayNotHasKey('Strict-Transport-Security', (new SecurityHeaders(false))->values());
    }

    public function testInjectedStyleElementsAreRefusedWhileStyleAttributesRemainAdmitted(): void
    {
        $policy = (new SecurityHeaders(true))->values()['Content-Security-Policy'];

        self::assertStringContainsString("style-src 'self';", $policy);
        self::assertStringContainsString("style-src-elem 'self'", $policy);
        self::assertStringContainsString("style-src-attr 'unsafe-inline'", $policy);
        self::assertStringNotContainsString("style-src 'self' 'unsafe-inline'", $policy);
    }
}
