<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use Kumwe\App\Studio\Application\Media\StudioExternalAddressResolver;
use Kumwe\App\Studio\Application\Media\StudioExternalMediaFetcher;
use Kumwe\App\Studio\Application\Media\StudioFetchedMedia;
use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Application\Media\StudioMediaSignatureVerifier;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpResponse;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpTransport;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Exercises DNS rebinding, redirect revalidation and response verification around the hardened fetcher.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioExternalMediaFetcher::class)]
#[CoversClass(StudioFetchedMedia::class)]
#[CoversClass(StudioPinnedHttpResponse::class)]
#[CoversClass(StudioMediaPortRejected::class)]
final class StudioExternalMediaFetcherTest extends TestCase
{
    /**
     * A DNS set containing any private answer is refused before the transport is called.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMixedPublicAndPrivateDnsAnswersFailClosedBeforeTransport(): void
    {
        $transport = $this->createMock(StudioPinnedHttpTransport::class);
        $transport->expects(self::never())->method('get');
        $fetcher = new StudioExternalMediaFetcher(
            new StudioExternalUrlPolicy(),
            $this->resolver(['93.184.216.34', '10.0.0.7']),
            $transport,
            $this->signatures('image/png'),
            ['image/png'],
            4096,
        );

        try {
            $fetcher->fetch('https://cdn.example/image.png');
            self::fail('A mixed DNS answer set must be refused.');
        } catch (StudioMediaPortRejected $failure) {
            self::assertSame('studio.media/external-host-refused', $failure->failureCode);
            self::assertStringNotContainsString('10.0.0.7', $failure->getMessage());
        }
    }

    /**
     * A redirect to a private literal is rejected without a second connection or candidate disclosure.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRedirectTargetRepeatsLexicalPolicy(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'studio-fetch-');
        self::assertIsString($path);
        file_put_contents($path, 'redirect');
        $transport = $this->createMock(StudioPinnedHttpTransport::class);
        $transport->expects(self::once())->method('get')->willReturn(new StudioPinnedHttpResponse(
            302,
            ['location' => 'https://127.0.0.1/private'],
            $path,
            8,
        ));
        $fetcher = new StudioExternalMediaFetcher(
            new StudioExternalUrlPolicy(),
            $this->resolver(['93.184.216.34']),
            $transport,
            $this->signatures('image/png'),
            ['image/png'],
            4096,
        );

        try {
            $fetcher->fetch('https://cdn.example/image.png');
            self::fail('The private redirect must be refused.');
        } catch (StudioMediaPortRejected $failure) {
            self::assertSame('studio.media/external-redirect-refused', $failure->failureCode);
            self::assertStringNotContainsString('127.0.0.1', $failure->getMessage());
        }
    }

    /**
     * Declared and detected response types must agree before the payload is returned.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testResponseTypeMismatchDeletesThePrivateBody(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'studio-fetch-');
        self::assertIsString($path);
        file_put_contents($path, 'body');
        $transport = self::createStub(StudioPinnedHttpTransport::class);
        $transport->method('get')->willReturn(new StudioPinnedHttpResponse(
            200,
            ['content-type' => 'image/jpeg', 'content-encoding' => 'identity'],
            $path,
            4,
        ));
        $fetcher = new StudioExternalMediaFetcher(
            new StudioExternalUrlPolicy(),
            $this->resolver(['93.184.216.34']),
            $transport,
            $this->signatures('image/png'),
            ['image/jpeg', 'image/png'],
            4096,
        );

        try {
            $fetcher->fetch('https://cdn.example/image.png');
            self::fail('A response type mismatch must be refused.');
        } catch (StudioMediaPortRejected $failure) {
            self::assertSame('studio.media/external-type-refused', $failure->failureCode);
            self::assertFileDoesNotExist($path);
        }
    }

    /**
     * Build a deterministic resolver answer set.
     *
     * @param   list<string>  $answers  Addresses to return.
     *
     * @return  StudioExternalAddressResolver
     *
     * @since  2.0.0
     */
    private function resolver(array $answers): StudioExternalAddressResolver
    {
        /** @var StudioExternalAddressResolver&Stub $resolver */
        $resolver = self::createStub(StudioExternalAddressResolver::class);
        $resolver->method('resolve')->willReturn($answers);

        return $resolver;
    }

    /**
     * Build a deterministic signature result.
     *
     * @param   string|null  $mediaType  Type to report.
     *
     * @return  StudioMediaSignatureVerifier
     *
     * @since  2.0.0
     */
    private function signatures(?string $mediaType): StudioMediaSignatureVerifier
    {
        /** @var StudioMediaSignatureVerifier&Stub $signatures */
        $signatures = self::createStub(StudioMediaSignatureVerifier::class);
        $signatures->method('verify')->willReturn($mediaType);

        return $signatures;
    }
}
