<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use InvalidArgumentException;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Automation\Job\PurgeIdempotencyRecordsHandler;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\RecordingRetentionDrain;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins that the system.idempotency.purge job drains its store inside the catalogue budget.
 *
 * A payload may only narrow that budget, never widen it.
 *
 * @since  2.0.0
 */
#[CoversClass(PurgeIdempotencyRecordsHandler::class)]
final class PurgeIdempotencyRecordsHandlerTest extends TestCase
{
    /**
     * An empty payload drains the declared store with the catalogue's declared budget unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEmptyPayloadDrainsWithTheDeclaredBudget(): void
    {
        $drain = new RecordingRetentionDrain();
        $this->handler($drain)->handle([], $this->context());

        self::assertCount(1, $drain->requests);
        self::assertSame(RetentionStore::DeliveryIdempotency, $drain->requests[0]['store']);
        self::assertEquals(
            RetentionCatalogue::declared()->policy(RetentionStore::DeliveryIdempotency)->budget(),
            $drain->requests[0]['budget'],
        );
    }

    /**
     * Historical batch keys and a shorter time budget narrow the run rather than bounding it by count alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPayloadKeysNarrowTheBudget(): void
    {
        $drain = new RecordingRetentionDrain();
        $this->handler($drain)->handle(
            ['batch_size' => 500, 'maximum_batches' => 2, 'time_budget_seconds' => 5],
            $this->context(),
        );

        $budget = $drain->requests[0]['budget'];
        self::assertSame(500, $budget->maximumBatch);
        self::assertSame(2, $budget->maximumBatches);
        self::assertSame(5, $budget->timeBudgetSeconds);
    }

    /**
     * A batch ceiling above the store's declared bound is refused before anything is drained.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABatchSizeBeyondTheDeclaredBoundIsRefused(): void
    {
        $drain = new RecordingRetentionDrain();
        try {
            $this->handler($drain)->handle(['batch_size' => 10_001], $this->context());
            self::fail('A widened batch ceiling was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $drain->requests);
        }
    }

    /**
     * Non-integer and out-of-range overrides are refused, including a batch cap above one hundred.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedOverridesAreRefused(): void
    {
        foreach (
            [['batch_size' => '500'], ['maximum_batches' => 0], ['maximum_batches' => 101],
            ['time_budget_seconds' => 3_600]] as $payload
        ) {
            $drain = new RecordingRetentionDrain();
            try {
                $this->handler($drain)->handle($payload, $this->context());
                self::fail('A malformed override was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $drain->requests);
            }
        }
    }

    /**
     * An ordinary worker principal is refused before the drain runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnOrdinaryWorkerPrincipalBeforePurging(): void
    {
        $drain = new RecordingRetentionDrain();
        try {
            $this->handler($drain)->handle([], AuthorizationContext::system(SystemIdentity::Worker)->context(
                SiteContext::default(),
                'wrong-global-principal',
            ));
            self::fail('An ordinary worker was allowed to run installation maintenance.');
        } catch (AuthorizationDenied) {
            self::assertSame([], $drain->requests);
        }
    }

    /**
     * The handler answers the job type its seeded schedule names.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeclaresTheScheduledJobType(): void
    {
        self::assertSame('system.idempotency.purge', $this->handler(new RecordingRetentionDrain())->type());
    }

    /**
     * Build the installation-maintenance context the schedule runs under.
     *
     * @return  ExecutionContext  System context.
     *
     * @since   2.0.0
     */
    private function context(): ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::InstallationMaintenance)->context(
            SiteContext::default(),
            'retention-purge-test',
        );
    }

    /**
     * Build the handler under test.
     *
     * @param   RecordingRetentionDrain  $drain  Recording drain double.
     *
     * @return  PurgeIdempotencyRecordsHandler  Handler.
     *
     * @since   2.0.0
     */
    private function handler(RecordingRetentionDrain $drain): PurgeIdempotencyRecordsHandler
    {
        return new PurgeIdempotencyRecordsHandler(
            $drain,
            RetentionCatalogue::declared(),
            AuthorizationContext::gateway(),
        );
    }
}
