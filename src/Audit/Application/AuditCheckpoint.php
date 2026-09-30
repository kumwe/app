<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

use InvalidArgumentException;

/**
 * A verified high-water mark of the audit trail, retained outside the database it describes.
 *
 * Hashes, witness links and anchors are all stored in the same database, so a principal able to erase
 * or roll back the trail together with its anchor ledger leaves behind a smaller trail that verifies
 * cleanly. A checkpoint records how far the trail and ledger had provably reached: the newest ledger
 * sequence with its chained digest, and the highest audit position the trail covered, counting archived
 * ranges. Any later verification must still reach that mark with the same ledger digest, so disappearance
 * or rollback is reported instead of verifying clean. Legitimate operation only ever advances both values.
 *
 * @since  2.0.0
 */
final readonly class AuditCheckpoint
{
    /**
     * Format marker every serialized checkpoint carries.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int FORMAT = 1;

    /**
     * Capture one verified high-water mark.
     *
     * @param   int      $ledgerSequence  Newest anchor ledger sequence verified, zero for an empty ledger.
     * @param   ?string  $ledgerDigest    Chained digest of that ledger entry, null exactly when the ledger is empty.
     * @param   int      $headPosition    Highest audit position present or archived when verified.
     *
     * @throws  InvalidArgumentException  When a value is negative or the digest does not match the sequence.
     *
     * @since   2.0.0
     */
    public function __construct(
        public int $ledgerSequence,
        public ?string $ledgerDigest,
        public int $headPosition,
    ) {
        if ($ledgerSequence < 0 || $headPosition < 0) {
            throw new InvalidArgumentException('An audit checkpoint cannot hold a negative position.');
        }
        if (($ledgerSequence === 0) !== ($ledgerDigest === null)) {
            throw new InvalidArgumentException(
                'An audit checkpoint names a ledger digest exactly when it names an entry.',
            );
        }
        if ($ledgerDigest !== null && preg_match('/^[0-9a-f]{64}$/D', $ledgerDigest) !== 1) {
            throw new InvalidArgumentException('An audit checkpoint ledger digest must be lowercase SHA-256.');
        }
    }

    /**
     * Rebuild a checkpoint from its decoded JSON form.
     *
     * The verification command prints the checkpoint as its own `checkpoint` member, so a saved command
     * result is accepted as well as the bare checkpoint object.
     *
     * @param   mixed  $value  Decoded JSON value.
     *
     * @return  self  The checkpoint the value describes.
     *
     * @throws  InvalidArgumentException  When the value is not a well-formed checkpoint.
     *
     * @since   2.0.0
     */
    public static function fromArray(mixed $value): self
    {
        if (is_array($value) && is_array($value['checkpoint'] ?? null)) {
            $value = $value['checkpoint'];
        }
        $digest = is_array($value) ? $value['ledger_digest'] ?? null : null;
        if (
            !is_array($value)
            || ($value['kumwe_audit_checkpoint'] ?? null) !== self::FORMAT
            || !is_int($value['ledger_sequence'] ?? null)
            || !is_int($value['head_position'] ?? null)
            || !($digest === null || is_string($digest))
        ) {
            throw new InvalidArgumentException('The audit checkpoint is not a Kumwe audit checkpoint document.');
        }

        return new self($value['ledger_sequence'], $digest, $value['head_position']);
    }

    /**
     * Serialize the checkpoint for retention outside the database.
     *
     * @return  array{kumwe_audit_checkpoint: int, ledger_sequence: int, ledger_digest: ?string, head_position: int}
     *          Stable JSON-ready form.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'kumwe_audit_checkpoint' => self::FORMAT,
            'ledger_sequence' => $this->ledgerSequence,
            'ledger_digest' => $this->ledgerDigest,
            'head_position' => $this->headPosition,
        ];
    }

    /**
     * Report whether this checkpoint has reached at least as far as another one in both dimensions.
     *
     * @param   self  $other  Checkpoint to compare with.
     *
     * @return  bool  True when neither the ledger sequence nor the head position is lower than the other's.
     *
     * @since   2.0.0
     */
    public function reaches(self $other): bool
    {
        return $this->ledgerSequence >= $other->ledgerSequence && $this->headPosition >= $other->headPosition;
    }
}
