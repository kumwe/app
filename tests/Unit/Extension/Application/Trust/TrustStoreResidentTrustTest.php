<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Application\Trust;

use Kumwe\App\Extension\Application\Trust\RuntimePublicationMismatch;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\App\Tests\Support\ResidentTrustFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Proves the reader path of the trust boundary: a committed trust read fenced by the loaded generation.
 *
 * `residentRuntimeTrusted()` is what every resident extension boundary asks before it lets code run or
 * reports it available. It must never take the lifecycle lock, must require the loaded generation on
 * both sides of the trust read, must re-check a disagreement exactly once, and must keep a verdict about
 * the package distinct from a trust authority it could not read.
 *
 * @since  2.0.0
 */
#[CoversClass(TrustStore::class)]
final class TrustStoreResidentTrustTest extends TestCase
{
    use ResidentTrustFixtures;

    /**
     * Prove a trusted entry is admitted from one fenced read and the lifecycle lock is never taken.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATrustedEntryIsAdmittedFromOneFencedReadWithoutTheLifecycleLock(): void
    {
        $repository = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $repository->expects(self::once())->method('installedRelease');

        self::assertTrue(self::probeTrustStore($repository)->residentRuntimeTrusted(
            self::scriptedGate(true, true),
            self::probeExtension(),
            self::probeRuntimeEntry(),
        ));
    }

    /**
     * Prove a stale generation answers false before trust is read at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleGenerationAnswersFalseWithoutReadingTrust(): void
    {
        $repository = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $repository->expects(self::never())->method('lockGeneration');

        self::assertFalse(self::probeTrustStore($repository)->residentRuntimeTrusted(
            self::scriptedGate(false),
            self::probeExtension(),
            self::probeRuntimeEntry(),
        ));
    }

    /**
     * Prove a generation that moves during the read is re-checked exactly once, then fails closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGenerationThatMovesDuringTheReadIsRecheckedOnceThenFailsClosed(): void
    {
        $moved = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $moved->expects(self::once())->method('installedRelease');
        self::assertFalse(self::probeTrustStore($moved)->residentRuntimeTrusted(
            self::scriptedGate(true, false, false),
            self::probeExtension(),
            self::probeRuntimeEntry(),
        ), 'A generation that stays moved must fail closed without a second read.');

        $flapping = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $flapping->expects(self::exactly(2))->method('installedRelease');
        self::assertFalse(self::probeTrustStore($flapping)->residentRuntimeTrusted(
            self::scriptedGate(true, false, true, false),
            self::probeExtension(),
            self::probeRuntimeEntry(),
        ), 'A second disagreement must fail closed rather than retry again.');

        $transient = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $transient->expects(self::exactly(2))->method('installedRelease');
        self::assertTrue(self::probeTrustStore($transient)->residentRuntimeTrusted(
            self::scriptedGate(true, false, true, true),
            self::probeExtension(),
            self::probeRuntimeEntry(),
        ), 'One transient disagreement must be re-read once and then admitted.');
    }

    /**
     * Prove distrust verdicts still surface as themselves, quarantining exactly when they did before.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDistrustVerdictsSurfaceUnchangedAndQuarantineOnlyAnUntrustedRelease(): void
    {
        $revoked = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease('revoked'),
        );
        $revoked->expects(self::once())->method('quarantineExtension')->willReturn(true);
        try {
            self::probeTrustStore($revoked)->residentRuntimeTrusted(
                self::scriptedGate(true),
                self::probeExtension(),
                self::probeRuntimeEntry(),
            );
            self::fail('A revoked release must be refused as untrusted.');
        } catch (UntrustedPackage) {
            self::addToAssertionCount(1);
        }

        $inactive = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [],
            self::probeRelease(),
        );
        $inactive->expects(self::never())->method('quarantineExtension');
        $this->expectException(RuntimePublicationMismatch::class);

        self::probeTrustStore($inactive)->residentRuntimeTrusted(
            self::scriptedGate(true),
            self::probeExtension(),
            self::probeRuntimeEntry(),
        );
    }

    /**
     * Prove an unreadable authority is logged, chained and refused, and never quarantines anything.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnreadableTrustAuthorityIsLoggedAndRefusedWithoutQuarantine(): void
    {
        $cause = new RuntimeException('server has gone away');
        $repository = $this->createMock(TrustStoreRepository::class);
        $repository->method('lockGeneration')->willThrowException($cause);
        $repository->expects(self::never())->method('quarantineExtension');
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Extension trust could not be determined; the extension is refused.',
            [
                'event' => 'extension.trust.indeterminate',
                'extension' => 'acme/probe',
                'reason' => RuntimeException::class,
                'message' => 'server has gone away',
            ],
        );

        try {
            self::probeTrustStore($repository, $logger)->enforceRuntimeTrust(self::probeExtension());
            self::fail('An unreadable trust authority must be refused.');
        } catch (RuntimeException $refused) {
            self::assertNotInstanceOf(RuntimePublicationMismatch::class, $refused);
            self::assertSame(
                'The extension trust authority could not be read; the extension is refused until it can be.',
                $refused->getMessage(),
            );
            self::assertSame($cause, $refused->getPrevious());
        }

        $unlogged = $this->createStub(TrustStoreRepository::class);
        $unlogged->method('lockGeneration')->willThrowException($cause);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The extension trust authority could not be read');

        self::probeTrustStore($unlogged)->residentRuntimeTrusted(
            self::scriptedGate(true),
            self::probeExtension(),
            self::probeRuntimeEntry(),
        );
    }
}
