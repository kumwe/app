<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Audit\Application;

use InvalidArgumentException;
use Kumwe\App\Audit\Application\AuditCheckpoint;
use Kumwe\App\Audit\Application\AuditRetentionAuthorityState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditCheckpoint::class)]
#[CoversClass(AuditRetentionAuthorityState::class)]
final class AuditCheckpointTest extends TestCase
{
    /**
     * A checkpoint round-trips through its JSON form, bare or inside a saved verification result.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACheckpointRoundTripsBareAndInsideAVerificationResult(): void
    {
        $checkpoint = new AuditCheckpoint(4, str_repeat('c', 64), 90);

        self::assertEquals($checkpoint, AuditCheckpoint::fromArray($checkpoint->toArray()));
        self::assertEquals($checkpoint, AuditCheckpoint::fromArray(['checkpoint' => $checkpoint->toArray()]));
        self::assertEquals(new AuditCheckpoint(0, null, 0), AuditCheckpoint::fromArray(
            (new AuditCheckpoint(0, null, 0))->toArray(),
        ));
    }

    /**
     * A checkpoint reaches another only when neither dimension is lower.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReachingRequiresBothTheLedgerAndTheHead(): void
    {
        $base = new AuditCheckpoint(2, str_repeat('a', 64), 10);

        self::assertTrue($base->reaches($base));
        self::assertTrue((new AuditCheckpoint(3, str_repeat('b', 64), 11))->reaches($base));
        self::assertFalse((new AuditCheckpoint(1, str_repeat('b', 64), 11))->reaches($base));
        self::assertFalse((new AuditCheckpoint(3, str_repeat('b', 64), 9))->reaches($base));
    }

    /**
     * Every malformed checkpoint is refused rather than weakened into a lower mark.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedCheckpointsAreRefused(): void
    {
        $valid = (new AuditCheckpoint(1, str_repeat('a', 64), 3))->toArray();
        foreach (
            [
                'negative head' => static fn (): AuditCheckpoint => new AuditCheckpoint(0, null, -1),
                'negative sequence' => static fn (): AuditCheckpoint => new AuditCheckpoint(-1, null, 0),
                'digest without entry' => static fn (): AuditCheckpoint => new AuditCheckpoint(
                    0,
                    str_repeat('a', 64),
                    0,
                ),
                'entry without digest' => static fn (): AuditCheckpoint => new AuditCheckpoint(1, null, 0),
                'uppercase digest' => static fn (): AuditCheckpoint => new AuditCheckpoint(1, str_repeat('A', 64), 0),
                'not an object' => static fn (): AuditCheckpoint => AuditCheckpoint::fromArray('checkpoint'),
                'unknown format' => static fn (): AuditCheckpoint => AuditCheckpoint::fromArray(
                    ['kumwe_audit_checkpoint' => 2] + $valid,
                ),
                'string sequence' => static fn (): AuditCheckpoint => AuditCheckpoint::fromArray(
                    ['ledger_sequence' => '1'] + $valid,
                ),
                'string head' => static fn (): AuditCheckpoint => AuditCheckpoint::fromArray(
                    ['head_position' => '3'] + $valid,
                ),
                'numeric digest' => static fn (): AuditCheckpoint => AuditCheckpoint::fromArray(
                    ['ledger_digest' => 7] + $valid,
                ),
            ] as $case => $build
        ) {
            try {
                $build();
                self::fail(sprintf('The %s checkpoint must be refused.', $case));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Only a separated principal, or the principal-less test engine, lets retention run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetentionAuthorityPosturesAreReportedDistinctly(): void
    {
        $summaries = [];
        foreach (AuditRetentionAuthorityState::cases() as $state) {
            $summaries[$state->summary()] = true;
            self::assertSame(
                in_array(
                    $state,
                    [AuditRetentionAuthorityState::Separated, AuditRetentionAuthorityState::SinglePrincipal],
                    true,
                ),
                $state->permitsRetention(),
                $state->value,
            );
            self::assertSame(
                in_array(
                    $state,
                    [AuditRetentionAuthorityState::NotInstalled, AuditRetentionAuthorityState::NotSeparated],
                    true,
                ),
                $state->degradesPrevention(),
                $state->value,
            );
        }
        self::assertCount(count(AuditRetentionAuthorityState::cases()), $summaries);
    }
}
