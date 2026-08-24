<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Domain\Media;

use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlRejection;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves the independent PHP lexical policy rejects alternate private-address spellings before DNS.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioExternalUrlPolicy::class)]
#[CoversClass(StudioExternalUrlRejection::class)]
#[CoversClass(StudioExternalUrlVerdict::class)]
final class StudioExternalUrlPolicyTest extends TestCase
{
    /**
     * Supply public and special-use candidates, including WHATWG numeric IPv4 forms.
     *
     * @return iterable<string, array{string, bool, StudioExternalUrlRejection|null}>
     *
     * @since  2.0.0
     */
    public static function candidates(): iterable
    {
        yield 'public name' => ['https://cdn.example.com/image.png', true, null];
        yield 'public ipv4' => ['https://8.8.8.8/image.png', true, null];
        yield 'http metadata' => [
            'http://169.254.169.254/latest/meta-data',
            false,
            StudioExternalUrlRejection::SchemeNotAllowed,
        ];
        yield 'loopback' => ['https://127.0.0.1/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'single decimal' => ['https://2130706433/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'hex' => ['https://0x7f000001/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'octal' => ['https://0177.0.0.1/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'single octal' => ['https://017700000001/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'dotted partial' => ['https://127.1/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'hex dotted' => ['https://0xc0.0xa8.0x0.0x1/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'numeric trailing dot' => ['https://2130706433./a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'malformed octal host' => ['https://08/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'malformed numeric-ending host' => [
            'https://example.08/a',
            false,
            StudioExternalUrlRejection::HostNotAllowed,
        ];
        yield 'cgnat' => ['https://100.64.0.1/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'mapped ipv6' => ['https://[::ffff:127.0.0.1]/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'compatible ipv6' => ['https://[::127.0.0.1]/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'ipv6 unique local' => ['https://[fd00::1]/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'ipv6 link local' => ['https://[fe80::1]/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'ipv6 unspecified' => ['https://[::]/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'public mapped ipv6' => ['https://[::ffff:808:808]/a', true, null];
        yield 'local suffix' => ['https://printer.internal/a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'localhost trailing dot' => ['https://localhost./a', false, StudioExternalUrlRejection::HostNotAllowed];
        yield 'home arpa trailing dot' => [
            'https://nas.home.arpa./a',
            false,
            StudioExternalUrlRejection::HostNotAllowed,
        ];
        yield 'idn' => ['https://bücher.example/cover.png', true, null];
        yield 'credentials' => [
            'https://actor:secret@example.com/a',
            false,
            StudioExternalUrlRejection::CredentialsInUrl,
        ];
        yield 'relative' => ['/relative/path', false, StudioExternalUrlRejection::Malformed];
        yield 'too long' => [
            'https://example.com/' . str_repeat('a', 2048),
            false,
            StudioExternalUrlRejection::UrlTooLong,
        ];
    }

    /**
     * Apply the canonical lexical policy to one candidate.
     *
     * @param   string                           $candidate  Untrusted URL candidate.
     * @param   bool                             $accepted   Expected acceptance state.
     * @param   StudioExternalUrlRejection|null  $rejection Expected stable rejection.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    #[DataProvider('candidates')]
    public function testCandidate(
        string $candidate,
        bool $accepted,
        ?StudioExternalUrlRejection $rejection,
    ): void {
        $verdict = (new StudioExternalUrlPolicy())->validate($candidate);

        self::assertSame($accepted, $verdict->acceptedUrl());
        self::assertSame($rejection, $verdict->rejection);
    }

    /**
     * Revalidate each redirect authority instead of inheriting the first hop's trust.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function testRedirectIsRevalidated(): void
    {
        $policy = new StudioExternalUrlPolicy();

        self::assertTrue($policy->redirect('https://cdn.example/a/b', '../image.png')->acceptedUrl());
        self::assertSame(
            StudioExternalUrlRejection::HostNotAllowed,
            $policy->redirect('https://cdn.example/a', 'https://127.0.0.1/secret')->rejection,
        );
    }

    /**
     * Runtime address classification independently excludes CGNAT even where filter tables differ.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testResolvedAddressesMustBeGloballyRoutable(): void
    {
        $policy = new StudioExternalUrlPolicy();

        self::assertFalse($policy->permitsResolvedAddress('100.64.0.1'));
        self::assertFalse($policy->permitsResolvedAddress('169.254.169.254'));
        self::assertFalse($policy->permitsResolvedAddress('fd00::1'));
        self::assertTrue($policy->permitsResolvedAddress('8.8.8.8'));
        self::assertTrue($policy->permitsResolvedAddress('2606:4700:4700::1111'));
    }
}
