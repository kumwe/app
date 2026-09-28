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
use RuntimeException;

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
     * A host that resolves to a public address for the first hop and to a refused one when a redirect sends the
     * fetch back to it is refused before the second connection: every hop re-resolves and re-classifies, and
     * the pinned transport only ever connects to the address the check approved. No refusal names the host or
     * either address.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHostThatRebindsToARefusedAddressAfterTheCheckIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'studio-fetch-');
        self::assertIsString($path);
        file_put_contents($path, 'redirect');
        $resolver = $this->createMock(StudioExternalAddressResolver::class);
        $resolver->expects(self::exactly(2))->method('resolve')->with('cdn.example')->willReturnOnConsecutiveCalls(
            ['93.184.216.34'],
            ['169.254.169.254'],
        );
        $transport = $this->createMock(StudioPinnedHttpTransport::class);
        $transport->expects(self::once())->method('get')
            ->with('https://cdn.example/image.png', '93.184.216.34', 4096, self::greaterThan(0))
            ->willReturn(new StudioPinnedHttpResponse(302, ['location' => '/moved.png'], $path, 8));
        $fetcher = new StudioExternalMediaFetcher(
            new StudioExternalUrlPolicy(),
            $resolver,
            $transport,
            $this->signatures('image/png'),
            ['image/png'],
            4096,
        );

        try {
            $fetcher->fetch('https://cdn.example/image.png');
            self::fail('A host rebinding to a link-local address after the check must be refused.');
        } catch (StudioMediaPortRejected $failure) {
            self::assertSame('studio.media/external-host-refused', $failure->failureCode);
            foreach (['cdn.example', '169.254.169.254', '93.184.216.34', 'moved.png'] as $disclosed) {
                self::assertStringNotContainsString($disclosed, $failure->getMessage());
            }
        }
        self::assertFileDoesNotExist($path);
    }

    /**
     * Every refusal category is stable and never discloses the candidate address or its resolved answers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoRefusalCategoryDisclosesTheCandidate(): void
    {
        $candidate = 'https://secret-host.example/private-path.png';
        $cases = [
            'lexical' => ['http://secret-host.example/private-path.png', ['93.184.216.34'], null],
            'resolution' => [$candidate, ['10.1.2.3'], null],
            'redirect' => [
                $candidate,
                ['93.184.216.34'],
                [302, ['location' => 'https://127.0.0.1/private-path.png'], 8],
            ],
            'response type' => [$candidate, ['93.184.216.34'], [200, ['content-type' => 'text/html'], 4]],
        ];
        foreach ($cases as $case => [$url, $answers, $response]) {
            $path = tempnam(sys_get_temp_dir(), 'studio-fetch-');
            self::assertIsString($path);
            file_put_contents($path, 'body');
            $transport = self::createStub(StudioPinnedHttpTransport::class);
            if ($response !== null) {
                [$status, $headers, $bytes] = $response;
                $transport->method('get')->willReturn(new StudioPinnedHttpResponse($status, $headers, $path, $bytes));
            }
            $fetcher = new StudioExternalMediaFetcher(
                new StudioExternalUrlPolicy(),
                $this->resolver($answers),
                $transport,
                $this->signatures('image/png'),
                ['image/png'],
                4096,
            );
            try {
                $fetcher->fetch($url);
                self::fail('The unsafe candidate was accepted: ' . $case);
            } catch (StudioMediaPortRejected $failure) {
                foreach (['secret-host', 'private-path', '10.1.2.3', '127.0.0.1', '93.184.216.34'] as $disclosed) {
                    self::assertStringNotContainsString($disclosed, $failure->getMessage(), $case);
                }
            }
            @unlink($path);
        }
    }

    /**
     * Refuse malformed candidates, exhausted budgets and unusable DNS sets before opening a connection.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testPreConnectionRefusalsFailClosed(): void
    {
        $transport = $this->createMock(StudioPinnedHttpTransport::class);
        $transport->expects(self::never())->method('get');

        self::assertFetchRejected(
            'studio.media/external-url-refused',
            new StudioExternalMediaFetcher(
                new StudioExternalUrlPolicy(),
                $this->resolver(['93.184.216.34']),
                $transport,
                $this->signatures('image/png'),
                ['image/png'],
                4096,
            ),
            'http://cdn.example/image.png',
        );
        self::assertFetchRejected(
            'studio.media/external-timeout',
            new StudioExternalMediaFetcher(
                new StudioExternalUrlPolicy(),
                $this->resolver(['93.184.216.34']),
                $transport,
                $this->signatures('image/png'),
                ['image/png'],
                4096,
                timeoutSeconds: 0,
            ),
            'https://cdn.example/image.png',
        );
        self::assertFetchRejected(
            'studio.media/external-host-refused',
            new StudioExternalMediaFetcher(
                new StudioExternalUrlPolicy(),
                $this->resolver([]),
                $transport,
                $this->signatures('image/png'),
                ['image/png'],
                4096,
            ),
            'https://cdn.example/image.png',
        );
    }

    /**
     * Preserve typed transport refusals while mapping all other transport faults to one safe diagnostic.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testTransportFaultsNeverLeakTheirCause(): void
    {
        $typed = self::createStub(StudioPinnedHttpTransport::class);
        $typed->method('get')->willThrowException(new StudioMediaPortRejected(
            'limit-exceeded',
            'studio.media/transport-refused',
        ));
        self::assertFetchRejected(
            'studio.media/transport-refused',
            $this->fetcher($typed),
            'https://cdn.example/image.png',
        );

        $faulted = self::createStub(StudioPinnedHttpTransport::class);
        $faulted->method('get')->willThrowException(new RuntimeException('sensitive transport details'));
        self::assertFetchRejected(
            'studio.media/external-fetch-failed',
            $this->fetcher($faulted),
            'https://cdn.example/image.png',
        );
    }

    /**
     * Delete and refuse redirects or terminal responses that cannot satisfy the import contract.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRedirectAndTerminalResponseRefusalsDeletePrivateBodies(): void
    {
        $cases = [
            'redirect limit' => [
                302,
                ['location' => 'https://cdn.example/next.png'],
                8,
                0,
                'studio.media/external-redirect-limit',
            ],
            'missing redirect location' => [
                302,
                [],
                8,
                3,
                'studio.media/external-redirect-refused',
            ],
            'non-success response' => [
                404,
                ['content-type' => 'image/png'],
                8,
                3,
                'studio.media/external-response-refused',
            ],
            'empty response' => [
                200,
                ['content-type' => 'image/png'],
                0,
                3,
                'studio.media/external-response-refused',
            ],
            'encoded response' => [
                200,
                ['content-encoding' => 'gzip', 'content-type' => 'image/png'],
                8,
                3,
                'studio.media/external-response-refused',
            ],
        ];

        foreach ($cases as $case => [$status, $headers, $bytes, $redirects, $code]) {
            $path = tempnam(sys_get_temp_dir(), 'studio-fetch-');
            self::assertIsString($path);
            file_put_contents($path, 'response');
            $transport = self::createStub(StudioPinnedHttpTransport::class);
            $transport->method('get')->willReturn(new StudioPinnedHttpResponse(
                $status,
                $headers,
                $path,
                $bytes,
            ));
            self::assertFetchRejected(
                $code,
                new StudioExternalMediaFetcher(
                    new StudioExternalUrlPolicy(),
                    $this->resolver(['93.184.216.34']),
                    $transport,
                    $this->signatures('image/png'),
                    ['image/png'],
                    4096,
                    $redirects,
                ),
                'https://cdn.example/image.png',
                $case,
            );
            self::assertFileDoesNotExist($path, $case);
        }
    }

    /**
     * Build a fetcher whose only varying edge is its pinned transport.
     *
     * @param   StudioPinnedHttpTransport  $transport  Transport behavior under test.
     *
     * @return  StudioExternalMediaFetcher  Hardened fetcher.
     *
     * @since  2.0.0
     */
    private function fetcher(StudioPinnedHttpTransport $transport): StudioExternalMediaFetcher
    {
        return new StudioExternalMediaFetcher(
            new StudioExternalUrlPolicy(),
            $this->resolver(['93.184.216.34']),
            $transport,
            $this->signatures('image/png'),
            ['image/png'],
            4096,
        );
    }

    /**
     * Assert a fetch fails with one stable non-disclosing diagnostic.
     *
     * @param   string                      $code       Expected diagnostic code.
     * @param   StudioExternalMediaFetcher  $fetcher    Fetcher under test.
     * @param   string                      $candidate  Candidate passed to the boundary.
     * @param   string                      $case       Optional scenario label.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertFetchRejected(
        string $code,
        StudioExternalMediaFetcher $fetcher,
        string $candidate,
        string $case = '',
    ): void {
        try {
            $fetcher->fetch($candidate);
            self::fail('The unsafe Studio external fetch was accepted: ' . $case);
        } catch (StudioMediaPortRejected $failure) {
            self::assertSame($code, $failure->failureCode, $case);
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
