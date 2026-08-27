<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;

/**
 * The declared ceilings one aggregate document command may spend, refused rather than exceeded.
 *
 * P4-B asks for caps on statement parameter count, SQL bytes, payload bytes, memory and transaction
 * time. The statement-level pair lives in the write repository, where statements are built; this object
 * carries the command-level three. Each ceiling is a named constant rather than configuration, because a
 * budget an operator can widen quietly is not a budget — changing one is a reviewed change to this file.
 *
 * The refusal is a validation failure on a `budget` violation, not a transport error: a command that
 * exceeds a declared ceiling is asking for more than the platform promises, which is a property of the
 * request. The transaction rolls back whole, so a refusal mid-write leaves nothing behind — the same
 * atomicity every other document refusal already has.
 *
 * @since  2.0.0
 */
final readonly class DocumentWriteBudget
{
    /**
     * Largest encoded request payload one document command accepts, in bytes.
     *
     * Sized from the envelope's own arithmetic: a thousand-line document at the capacity contract's
     * measured line widths encodes far below one mebibyte, so four of them is headroom, not a squeeze.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_PAYLOAD_BYTES = 4_194_304;

    /**
     * Largest memory growth one document command may cause, in bytes.
     *
     * Measured as the delta from the command's entry, so a busy worker's standing allocation does not
     * count against the command that happens to run next.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_MEMORY_DELTA_BYTES = 134_217_728;

    /**
     * Longest one document commit may hold its transaction, in milliseconds.
     *
     * This is the capacity contract's p99 objective for a thousand-line commit. Holding the declared
     * worst-case latency as the hard ceiling means a commit that would blow the contract is aborted and
     * rolled back rather than finishing late while holding locks the whole time.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_TRANSACTION_MILLISECONDS = 15_000;

    /**
     * Refuse a command whose encoded request payload exceeds the declared ceiling.
     *
     * @param   int  $bytes  Encoded payload size of the submitted command.
     *
     * @return  void
     *
     * @throws  BusinessRecordValidationFailed  When the payload is over budget.
     *
     * @since   2.0.0
     */
    public function assertPayloadWithin(int $bytes): void
    {
        if ($bytes > self::MAXIMUM_PAYLOAD_BYTES) {
            throw new BusinessRecordValidationFailed([new ValidationViolation(
                'document',
                'budget',
                sprintf(
                    'The document payload of %d bytes exceeds the declared ceiling of %d bytes.',
                    $bytes,
                    self::MAXIMUM_PAYLOAD_BYTES,
                ),
            )]);
        }
    }

    /**
     * Refuse to continue a command whose memory growth exceeds the declared ceiling.
     *
     * @param   int  $startBytes  Memory usage measured when the command began.
     *
     * @return  void
     *
     * @throws  BusinessRecordValidationFailed  When the growth since entry is over budget.
     *
     * @since   2.0.0
     */
    public function assertMemoryWithin(int $startBytes): void
    {
        $delta = memory_get_usage() - $startBytes;
        if ($delta > self::MAXIMUM_MEMORY_DELTA_BYTES) {
            throw new BusinessRecordValidationFailed([new ValidationViolation(
                'document',
                'budget',
                sprintf(
                    'The document command grew memory by %d bytes, past the declared ceiling of %d bytes.',
                    $delta,
                    self::MAXIMUM_MEMORY_DELTA_BYTES,
                ),
            )]);
        }
    }

    /**
     * Refuse to continue a commit that has already held its transaction past the declared ceiling.
     *
     * @param   int|float  $startNanoseconds  Monotonic instant, from `hrtime(true)`, when the command
     *          began.
     *
     * @return  void
     *
     * @throws  BusinessRecordValidationFailed  When the elapsed time is over budget.
     *
     * @since   2.0.0
     */
    public function assertElapsedWithin(int|float $startNanoseconds): void
    {
        $elapsed = (hrtime(true) - $startNanoseconds) / 1_000_000;
        if ($elapsed > (float) self::MAXIMUM_TRANSACTION_MILLISECONDS) {
            throw new BusinessRecordValidationFailed([new ValidationViolation(
                'document',
                'budget',
                sprintf(
                    'The document commit held its transaction for %dms, past the declared ceiling of %dms.',
                    (int) $elapsed,
                    self::MAXIMUM_TRANSACTION_MILLISECONDS,
                ),
            )]);
        }
    }
}
