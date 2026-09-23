<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessRecordIdempotencyConflict::class)]
/**
 * Pins that a caller-minted key which outlives its replay window is refused by name afterwards.
 *
 * The window's own arithmetic — its defaults, its declared bounds and how operator configuration is read —
 * is `Kumwe\Record\Model\BusinessRecordReplayWindow`, proven by kumwe/record-model's
 * BusinessRecordReplayWindowTest. What App keeps here is the host half of the bargain: the refusal a late
 * repeat meets carries its own stable code, distinct from a reused key, so a duplicate that is announced can
 * be reconciled rather than silently becoming a second effect.
 *
 * @since  2.0.0
 */
final class BusinessRecordReplayWindowTest extends TestCase
{
    /**
     * A repeat arriving after the window is refused under its own stable code, not as a reused key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALateRepeatIsRefusedUnderItsOwnStableCode(): void
    {
        $elapsed = new BusinessRecordIdempotencyConflict('replay_window_elapsed');
        $reused = new BusinessRecordIdempotencyConflict('key_reused');

        self::assertSame('business_record.idempotency_replay_window_elapsed', $elapsed->stableCode());
        self::assertNotSame($reused->stableCode(), $elapsed->stableCode());
        self::assertStringContainsString('refused rather than applied a second time', $elapsed->getMessage());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conflict state is unsupported');
        new BusinessRecordIdempotencyConflict('expired');
    }
}
