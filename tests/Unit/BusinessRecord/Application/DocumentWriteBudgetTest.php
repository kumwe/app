<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use Kumwe\App\BusinessRecord\Application\DocumentWriteBudget;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Holds the document command's declared ceilings to refusing exactly at the line they draw.
 *
 * P4-B caps payload bytes, memory growth and transaction time; a budget that never refuses is a comment,
 * so each guard is proven from both sides — the last request inside the ceiling passes, the first one
 * past it is refused as a validation failure on a `budget` violation, which is what keeps a budget
 * refusal atomic and legible instead of surfacing as a driver error or a silent overrun.
 *
 * @since  2.0.0
 */
#[CoversClass(DocumentWriteBudget::class)]
final class DocumentWriteBudgetTest extends TestCase
{
    /**
     * A payload at the ceiling passes and one byte past it is refused as a budget violation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePayloadCeilingRefusesTheFirstByteOverBudget(): void
    {
        $budget = new DocumentWriteBudget();
        $budget->assertPayloadWithin(DocumentWriteBudget::MAXIMUM_PAYLOAD_BYTES);

        try {
            $budget->assertPayloadWithin(DocumentWriteBudget::MAXIMUM_PAYLOAD_BYTES + 1);
            self::fail('A payload past the declared ceiling must be refused.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame('budget', $exception->violations[0]->code);
        }
    }

    /**
     * Memory growth is measured from the command's entry, and refused only past the declared delta.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMemoryCeilingMeasuresGrowthSinceEntry(): void
    {
        $budget = new DocumentWriteBudget();
        $budget->assertMemoryWithin(memory_get_usage());

        try {
            $budget->assertMemoryWithin(
                memory_get_usage() - DocumentWriteBudget::MAXIMUM_MEMORY_DELTA_BYTES - 1024,
            );
            self::fail('Growth past the declared memory delta must be refused.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame('budget', $exception->violations[0]->code);
        }
    }

    /**
     * A commit inside the transaction-time ceiling passes and one past it is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheTransactionTimeCeilingRefusesAnOverlongCommit(): void
    {
        $budget = new DocumentWriteBudget();
        $budget->assertElapsedWithin(hrtime(true));

        try {
            $budget->assertElapsedWithin(
                hrtime(true) - (DocumentWriteBudget::MAXIMUM_TRANSACTION_MILLISECONDS + 1000) * 1_000_000,
            );
            self::fail('A commit past the declared transaction-time ceiling must be refused.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame('budget', $exception->violations[0]->code);
        }
    }
}
