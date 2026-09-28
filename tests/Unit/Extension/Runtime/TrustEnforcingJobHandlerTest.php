<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\RuntimePublicationMismatch;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\App\Extension\Runtime\TrustEnforcingJobHandler;
use Kumwe\Extension\Spi\Application\Automation\JobHandler;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Automation\JobContributionDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Tests\Support\ResidentTrustFixtures;

#[CoversClass(TrustEnforcingJobHandler::class)]
#[UsesClass(TrustStore::class)]
/**
 * Proves a contributed job implementation runs only behind the live trust and boot-generation fence.
 *
 * The fence is the one Studio preview renderers carry: package trust is re-read from committed authority
 * against the exact signed runtime entry, the boot generation must be current before and after that read,
 * and the SDK implementation is reached only when every check passed. None of it takes the lifecycle lock,
 * which is reserved for mutators, so a trusted job is never refused because another reader held it.
 *
 * @since  2.0.0
 */
final class TrustEnforcingJobHandlerTest extends TestCase
{
    use ResidentTrustFixtures;

    /**
     * Prove a current, trusted generation hands the identical invocation to the delegate without the lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACurrentTrustedGenerationDelegatesTheExactInvocationWithoutTheLifecycleLock(): void
    {
        $definition = self::definition();
        $payload = ['site_identifier' => 'default'];
        $context = $this->createStub(ExecutionContext::class);
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::exactly(2))->method('isCurrent')->willReturn(true);
        $repository = $this->observedRepository([self::probeExtension()], self::probeRelease());
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $repository->expects(self::once())->method('installedRelease');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::once())->method('handle')->with(
            self::identicalTo($definition),
            self::identicalTo($payload),
            self::identicalTo($context),
        );
        $handler = $this->handler($inner, $repository, $execution);

        $handler->handle($definition, $payload, $context);
    }

    /**
     * Prove a stale generation is refused before trust is read and never reaches the delegate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleGenerationIsRefusedBeforeTrustIsRead(): void
    {
        $execution = $this->createStub(ExtensionExecutionGate::class);
        $execution->method('isCurrent')->willReturn(false);
        $repository = $this->observedRepository([self::probeExtension()], self::probeRelease());
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $repository->expects(self::never())->method('lockGeneration');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');
        $handler = $this->handler($inner, $repository, $execution);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This process cannot execute a stale or untrusted extension generation.');

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Prove a generation superseded during the trust read is re-checked once and then refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGenerationSupersededDuringTheTrustReadNeverReachesTheDelegate(): void
    {
        $answers = [true, false, false];
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::exactly(3))->method('isCurrent')->willReturnCallback(
            static function () use (&$answers): bool {
                return (bool) array_shift($answers);
            },
        );
        $repository = $this->observedRepository([self::probeExtension()], self::probeRelease());
        $repository->expects(self::once())->method('installedRelease');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');
        $handler = $this->handler($inner, $repository, $execution);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This process cannot execute a stale or untrusted extension generation.');

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Prove one transient disagreement between the two generation checks is re-read once and then runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATransientGenerationDisagreementIsRecheckedOnceBeforeTheDelegateRuns(): void
    {
        $answers = [true, false, true, true];
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::exactly(4))->method('isCurrent')->willReturnCallback(
            static function () use (&$answers): bool {
                return (bool) array_shift($answers);
            },
        );
        $repository = $this->observedRepository([self::probeExtension()], self::probeRelease());
        $repository->expects(self::exactly(2))->method('installedRelease');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::once())->method('handle');
        $handler = $this->handler($inner, $repository, $execution);

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Prove a release that no longer verifies is quarantined and refused before the delegate runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUntrustedPackageIsQuarantinedAndNeverReachesTheDelegate(): void
    {
        $repository = $this->observedRepository([self::probeExtension()], self::probeRelease('revoked'));
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $repository->expects(self::once())->method('quarantineExtension')
            ->with(self::probeExtension())
            ->willReturn(true);
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');
        $handler = $this->handler($inner, $repository, self::scriptedGate(true));

        $this->expectException(UntrustedPackage::class);

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Prove a compiled entry for a package that is no longer active is refused without quarantine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPublicationForAnInactivePackageIsRefusedWithoutQuarantine(): void
    {
        $repository = $this->observedRepository([], self::probeRelease());
        $repository->expects(self::never())->method('quarantineExtension');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');
        $handler = $this->handler($inner, $repository, self::scriptedGate(true));

        $this->expectException(RuntimePublicationMismatch::class);

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Prove availability holds only while both the generation and the package remain trusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAvailabilityHoldsOnlyWhileTheGenerationAndPackageRemainTrusted(): void
    {
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');

        $trusted = $this->observedRepository([self::probeExtension()], self::probeRelease());
        $trusted->expects(self::never())->method('synchronizedLifecycle');
        self::assertTrue($this->handler($inner, $trusted, self::scriptedGate(true))->isAvailable());

        $untrusted = $this->repository([self::probeExtension()], self::probeRelease('revoked'));
        $untrusted->method('quarantineExtension')->willReturn(true);
        self::assertFalse($this->handler($inner, $untrusted, self::scriptedGate(true))->isAvailable());

        $inactive = $this->repository([], self::probeRelease());
        self::assertFalse($this->handler($inner, $inactive, self::scriptedGate(true))->isAvailable());
    }

    /**
     * Prove a stale generation reports unavailable without reading trust at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAvailabilityIsDeniedWithoutReadingTrustOnceTheGenerationIsStale(): void
    {
        $execution = $this->createStub(ExtensionExecutionGate::class);
        $execution->method('isCurrent')->willReturn(false);
        $repository = $this->observedRepository([self::probeExtension()], self::probeRelease());
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $repository->expects(self::never())->method('lockGeneration');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');

        self::assertFalse($this->handler($inner, $repository, $execution)->isAvailable());
    }

    /**
     * Prove an unreadable trust authority is an explicit refusal, never a distrust verdict.
     *
     * Availability must not answer false for it, nothing may be quarantined, and the job must not run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnreadableTrustAuthorityIsRefusedExplicitlyAndNeverReadAsDistrust(): void
    {
        $repository = $this->createMock(TrustStoreRepository::class);
        $repository->method('lockGeneration')->willThrowException(new RuntimeException('connection lost'));
        $repository->expects(self::never())->method('quarantineExtension');
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');
        $handler = $this->handler($inner, $repository, self::scriptedGate(true));

        try {
            $handler->isAvailable();
            self::fail('An unreadable trust authority must not be answered as a verdict.');
        } catch (RuntimeException $refused) {
            self::assertSame(
                'The extension trust authority could not be read; the extension is refused until it can be.',
                $refused->getMessage(),
            );
            self::assertSame('connection lost', $refused->getPrevious()?->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The extension trust authority could not be read');

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Wrap one delegate in the fence under a real trust boundary backed by the given repository double.
     *
     * @param   JobHandler              $inner       Delegate the fence guards.
     * @param   TrustStoreRepository    $repository  Repository double the trust boundary consults.
     * @param   ExtensionExecutionGate  $execution   Boot-generation gate double.
     *
     * @return  TrustEnforcingJobHandler  Fenced handler bound to the probe package entry.
     *
     * @since   2.0.0
     */
    private function handler(
        JobHandler $inner,
        TrustStoreRepository $repository,
        ExtensionExecutionGate $execution,
    ): TrustEnforcingJobHandler {
        return new TrustEnforcingJobHandler(
            $inner,
            self::probeTrustStore($repository),
            $execution,
            self::probeExtension(),
            self::probeRuntimeEntry(),
        );
    }

    /**
     * Build a repository stub reporting the given active set and installed release for the probe.
     *
     * @param   list<string>          $active   Extensions the store reports as active.
     * @param   array<string, mixed>  $release  Installed release record the store returns for the probe.
     *
     * @return  Stub&TrustStoreRepository  Repository double no test states expectations about.
     *
     * @since   2.0.0
     */
    private function repository(array $active, array $release): Stub&TrustStoreRepository
    {
        return self::probeRepository($this->createStub(TrustStoreRepository::class), $active, $release);
    }

    /**
     * Build a repository mock reporting the given active set and installed release for the probe.
     *
     * @param   list<string>          $active   Extensions the store reports as active.
     * @param   array<string, mixed>  $release  Installed release record the store returns for the probe.
     *
     * @return  MockObject&TrustStoreRepository  Repository double a test states lock or quarantine
     *          expectations about.
     *
     * @since   2.0.0
     */
    private function observedRepository(array $active, array $release): MockObject&TrustStoreRepository
    {
        return self::probeRepository($this->createMock(TrustStoreRepository::class), $active, $release);
    }

    /**
     * Build one signed job declaration for the probe package.
     *
     * @return  JobContributionDefinition  Signed probe job declaration.
     *
     * @since   2.0.0
     */
    private static function definition(): JobContributionDefinition
    {
        return JobContributionDefinition::fromArray(new DeterministicCanonicalEncoder(), [
            'job_type' => 'acme.probe.summarize',
            'schema_version' => 1,
            'handler_version' => '1.0.0',
            'payload_schema' => [
                'type' => 'object',
                'required' => ['site_identifier'],
                'properties' => ['site_identifier' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
            'queue' => 'acme.probe',
            'maximum_attempts' => 3,
            'installation_wide' => false,
        ]);
    }
}
