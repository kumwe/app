<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Security;

use Kumwe\App\Http\Security\SecurityHeaders;
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

    /**
     * A response that did not travel over TLS must not carry `upgrade-insecure-requests`.
     *
     * The directive tells the browser to refetch every subresource, and to resubmit every form, over
     * `https://`. An origin serving plain HTTP has nothing listening there, so the instruction does not
     * harden the page — it removes its stylesheets, its scripts and its ability to sign anyone in.
     * Chromium and Firefox hide that by exempting loopback; WebKit honours it, which is why the defect
     * reached a browser the merge lane never runs before any test caught it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDoesNotUpgradeSubresourcesOnAnOriginThatIsNotServedOverTls(): void
    {
        $policy = (new SecurityHeaders(false, false))->values()['Content-Security-Policy'];

        self::assertStringNotContainsString('upgrade-insecure-requests', $policy);
    }

    /**
     * A response that did travel over TLS keeps the directive, independently of HSTS.
     *
     * The two are gated on different questions: HSTS asks whether this deployment may pin a browser to
     * TLS for a year, which only production should answer yes to, while the upgrade directive asks only
     * whether this response arrived over TLS. A staging site served over HTTPS answers no to the first
     * and yes to the second, and must still upgrade its subresources.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUpgradesSubresourcesOverTlsEvenWhereHstsIsWithheld(): void
    {
        $policy = (new SecurityHeaders(false, true))->values()['Content-Security-Policy'];

        self::assertStringContainsString('upgrade-insecure-requests', $policy);
        self::assertArrayNotHasKey(
            'Strict-Transport-Security',
            (new SecurityHeaders(false, true))->values(),
        );
    }

    /**
     * Preview framing changes only the reviewed directives and admits no inline code, style, or eval.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testPreviewPolicyIsOtherwiseByteIdenticalToTheAdministratorPolicy(): void
    {
        $headers = new SecurityHeaders(false, false);
        $ordinary = self::directives($headers->values()['Content-Security-Policy']);
        $preview = self::directives($headers->previewValues()['Content-Security-Policy']);

        self::assertSame("frame-ancestors 'none'", $ordinary['frame-ancestors']);
        self::assertSame("frame-ancestors 'self'", $preview['frame-ancestors']);
        self::assertArrayNotHasKey('frame-src', $ordinary);
        self::assertSame("frame-src 'self'", $preview['frame-src']);
        self::assertSame("style-src-attr 'none'", $preview['style-src-attr']);
        unset($ordinary['frame-ancestors'], $ordinary['style-src-attr']);
        unset($preview['frame-ancestors'], $preview['frame-src'], $preview['style-src-attr']);
        self::assertSame($ordinary, $preview);

        $policy = $headers->previewValues()['Content-Security-Policy'];
        self::assertStringNotContainsString("'unsafe-inline'", $policy);
        self::assertStringNotContainsString("'unsafe-eval'", $policy);
        self::assertStringNotContainsString("'nonce-", $policy);
        self::assertSame('SAMEORIGIN', $headers->previewValues()['X-Frame-Options']);
        self::assertSame('no-referrer', $headers->previewValues()['Referrer-Policy']);
    }

    /**
     * Index a policy by directive name while preserving each directive's exact bytes.
     *
     * @param   string  $policy  Complete CSP policy string.
     *
     * @return  array<string, string>  Directive name to unmodified directive.
     *
     * @since  2.0.0
     */
    private static function directives(string $policy): array
    {
        $directives = [];
        foreach (explode('; ', $policy) as $directive) {
            $name = explode(' ', $directive, 2)[0];
            $directives[$name] = $directive;
        }

        return $directives;
    }
}
