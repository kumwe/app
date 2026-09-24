<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Retention;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Automation\Job\DrainRetentionStoreHandler;
use Kumwe\App\Application\Retention\RetentionBudget;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionDrainResult;
use Kumwe\App\Application\Retention\RetentionObservation;
use Kumwe\App\Application\Retention\RetentionPolicy;
use Kumwe\App\Application\Retention\RetentionReadiness;
use Kumwe\App\Application\Retention\RetentionReadinessState;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Application\Retention\RetentionVerdict;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\RecordingRetentionDrain;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the retention contract of V2-SCL-004 and V2-SCL-008: declarations, adaptive budgets and verdicts.
 *
 * @since  2.0.0
 */
#[CoversClass(RetentionCatalogue::class)]
#[CoversClass(RetentionPolicy::class)]
#[CoversClass(RetentionBudget::class)]
#[CoversClass(RetentionObservation::class)]
#[CoversClass(RetentionReadiness::class)]
#[CoversClass(RetentionVerdict::class)]
#[CoversClass(RetentionDrainResult::class)]
#[CoversClass(DrainRetentionStoreHandler::class)]
final class RetentionContractTest extends TestCase
{
    /**
     * Every store declares every clause, and each drainable ledger must drain twice the enterprise peak.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryStoreDeclaresEveryClauseAndTwiceThePeakExpiryDrain(): void
    {
        $catalogue = RetentionCatalogue::declared();
        self::assertCount(count(RetentionStore::cases()), $catalogue->policies());
        self::assertSame(926, RetentionCatalogue::REQUIRED_DRAIN_ROWS_PER_SECOND);
        foreach (RetentionStore::cases() as $store) {
            $policy = $catalogue->policy($store);
            foreach (
                [$policy->purpose, $policy->expiryStrategy, $policy->backupBehaviour, $policy->legalHoldBehaviour,
                    $policy->failureProcedure, $policy->reconciliationProcedure] as $clause
            ) {
                self::assertNotSame('', trim($clause), $store->value . ' leaves a clause empty.');
            }
            self::assertNotSame([], $policy->states);
            if ($policy->drainable()) {
                self::assertGreaterThanOrEqual(926, $policy->requiredDrainRowsPerSecond);
                self::assertNotNull($policy->drainJobType);
                self::assertNotSame([], $policy->requiredSettings);
                self::assertGreaterThan(0.0, $policy->dutyCycle());
            }
        }
        self::assertFalse($catalogue->policy(RetentionStore::Revisions)->drainable());
        self::assertTrue($catalogue->policy(RetentionStore::Audit)->immutable);
    }

    /**
     * A catalogue keyed by the wrong store, an unknown lookup and an invalid policy bound are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedDeclarationsAreRefused(): void
    {
        $policy = RetentionCatalogue::declared()->policy(RetentionStore::Audit);
        try {
            new RetentionCatalogue(['revisions' => $policy]);
            self::fail('A mis-keyed catalogue was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        try {
            (new RetentionCatalogue([]))->policy(RetentionStore::Audit);
            self::fail('An undeclared store was resolved.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        new RetentionPolicy(
            RetentionStore::Audit,
            'x',
            0,
            true,
            'none',
            ['x'],
            10,
            5,
            1,
            1,
            1,
            0,
            1,
            null,
            [],
            'x',
            'x',
            'x',
            'x'
        );
    }

    /**
     * The batch doubles inside half the lock budget, halves when it overruns and stays inside its bounds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheBatchAdaptsToTheLockBudget(): void
    {
        $budget = new RetentionBudget(30, 100, 1_000, 200);
        self::assertSame(100, $budget->initialBatch());
        self::assertSame(200, $budget->nextBatch(100, 10.0));
        self::assertSame(1_000, $budget->nextBatch(800, 10.0));
        self::assertSame(400, $budget->nextBatch(400, 150.0));
        self::assertSame(200, $budget->nextBatch(400, 500.0));
        self::assertSame(100, $budget->nextBatch(150, 500.0));
    }

    /**
     * A payload can narrow every bound but widening any of them, or an invalid bound, is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPayloadsMayOnlyNarrowTheBudget(): void
    {
        $budget = new RetentionBudget(30, 100, 1_000, 200, 50);
        $narrowed = $budget->narrowed(10, 50, 5);
        self::assertSame([10, 50, 50, 5], [$narrowed->timeBudgetSeconds, $narrowed->minimumBatch,
            $narrowed->maximumBatch, $narrowed->maximumBatches]);
        foreach ([[31, null, null], [null, 1_001, null], [null, null, 51]] as [$time, $batch, $batches]) {
            try {
                $budget->narrowed($time, $batch, $batches);
                self::fail('A widening override was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new RetentionBudget(0, 1, 1, 1);
    }

    /**
     * The forecast uses the duty-cycle-scaled drain and predicts nothing for a shrinking or unknown slope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheForecastFollowsTheNetSlope(): void
    {
        self::assertNull(RetentionObservation::forecast(10, 100, null, 5.0, 0.5));
        self::assertNull(RetentionObservation::forecast(10, 100, 2.0, 5.0, 0.5));
        self::assertSame(45.0, RetentionObservation::forecast(10, 100, 4.0, 4.0, 0.5));
        self::assertSame(0.0, RetentionObservation::forecast(200, 100, 1.0, null, 0.5));
        $result = new RetentionDrainResult(RetentionStore::Audit, 10, 2, 2.0, 5, true, false);
        self::assertSame(5.0, $result->rowsPerSecond());
        self::assertNull((new RetentionDrainResult(RetentionStore::Audit, 0, 1, 0.0, 5, true, false))->rowsPerSecond());
    }

    /**
     * Missing settings warn the baseline profile and fail the enterprise one; exhaustion fails either.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReadinessWarnsOrFailsOnSettingsAndDrainSlope(): void
    {
        $readiness = new RetentionReadiness(100, 1_000);
        $healthy = $this->observation(true, 0, null);
        self::assertSame(RetentionReadinessState::Ready, $readiness->assess([$healthy], true)->state);
        $unconfigured = $this->observation(false, 0, null);
        self::assertSame(RetentionReadinessState::Warning, $readiness->assess([$unconfigured], false)->state);
        $failed = $readiness->assess([$unconfigured], true);
        self::assertSame(RetentionReadinessState::Failed, $failed->state);
        self::assertFalse($failed->ready());
        self::assertStringContainsString('business_idempotency', $failed->reasons[0]);
        self::assertSame(
            RetentionReadinessState::Warning,
            $readiness->assess([$this->observation(true, 0, 500.0)], true)->state,
        );
        self::assertSame(
            RetentionReadinessState::Failed,
            $readiness->assess([$this->observation(true, 0, 50.0)], false)->state,
        );
        self::assertSame(
            RetentionReadinessState::Failed,
            $readiness->assess([$this->observation(true, 100, null)], false)->state,
        );
        $this->expectException(InvalidArgumentException::class);
        new RetentionReadiness(10, 5);
    }

    /**
     * The generic drain job resolves its store and refuses unknown, retained or dedicated-job stores.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGenericDrainJobResolvesOnlyItsOwnStores(): void
    {
        $drain = new RecordingRetentionDrain();
        $handler = new DrainRetentionStoreHandler(
            $drain,
            RetentionCatalogue::declared(),
            AuthorizationContext::gateway(),
        );
        $context = AuthorizationContext::system(SystemIdentity::InstallationMaintenance)->context(
            SiteContext::default(),
            'retention-drain-test',
        );
        self::assertSame('system.retention.drain', $handler->type());
        $handler->handle(['store' => 'inbox_receipts', 'batch_size' => 300], $context);
        self::assertSame(RetentionStore::InboxReceipts, $drain->requests[0]['store']);
        self::assertSame(300, $drain->requests[0]['budget']->maximumBatch);
        foreach (
            [[], ['store' => 'nope'], ['store' => 'revisions'], ['store' => 'business_idempotency'],
            ['store' => 'inbox_receipts', 'batch_size' => 'x']] as $payload
        ) {
            try {
                $handler->handle($payload, $context);
                self::fail('An invalid drain payload was accepted.');
            } catch (InvalidArgumentException) {
                self::assertCount(1, $drain->requests);
            }
        }
    }

    /**
     * Build an observation for the readiness cases.
     *
     * @param   bool    $configured  Whether required settings are present.
     * @param   int     $backlog     Eligible rows present; capacity is 100.
     * @param   ?float  $forecast    Forecast seconds, or null for none.
     *
     * @return  RetentionObservation  Observation.
     *
     * @since   2.0.0
     */
    private function observation(bool $configured, int $backlog, ?float $forecast): RetentionObservation
    {
        return new RetentionObservation(
            RetentionStore::BusinessIdempotency,
            new DateTimeImmutable('2026-09-24T00:00:00+00:00'),
            1.0,
            1.0,
            null,
            $backlog,
            false,
            null,
            $forecast,
            100,
            $configured,
            $configured ? [] : ['schedule:business.record.idempotency.purge is absent or disabled'],
            null,
        );
    }
}
