<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\App\Extension\Runtime\TrustEnforcingRequestHandler;
use Kumwe\App\Tests\Support\ResidentTrustFixtures;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Proves a contributed route answers only while its extension is trusted, without the lifecycle lock.
 *
 * Holding the lifecycle lock for the length of a request made two concurrent requests to any extension
 * route refuse each other. Trust is now re-read per request from committed authority, and an untrusted
 * release is still quarantined and refused before the contributed handler is reached.
 *
 * @since  2.0.0
 */
#[CoversClass(TrustEnforcingRequestHandler::class)]
#[UsesClass(TrustStore::class)]
final class TrustEnforcingRequestHandlerTest extends TestCase
{
    use ResidentTrustFixtures;

    /**
     * Prove a trusted extension's route answers with its own response and the lock is never taken.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATrustedExtensionRouteAnswersWithoutTheLifecycleLock(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/probe');
        $response = new EmptyResponse(204);
        $inner = $this->createMock(RequestHandlerInterface::class);
        $inner->expects(self::once())->method('handle')->with(self::identicalTo($request))->willReturn($response);
        $repository = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $handler = new TrustEnforcingRequestHandler($inner, self::probeTrustStore($repository), self::probeExtension());

        self::assertSame($response, $handler->handle($request));
    }

    /**
     * Prove a revoked extension's route is quarantined and refused before its handler is reached.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARevokedExtensionRouteIsQuarantinedAndNeverReachesItsHandler(): void
    {
        $inner = $this->createMock(RequestHandlerInterface::class);
        $inner->expects(self::never())->method('handle');
        $repository = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease('revoked'),
        );
        $repository->expects(self::once())->method('quarantineExtension')
            ->with(self::probeExtension())
            ->willReturn(true);
        $handler = new TrustEnforcingRequestHandler($inner, self::probeTrustStore($repository), self::probeExtension());

        $this->expectException(UntrustedPackage::class);

        $handler->handle((new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/probe'));
    }
}
