<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;

/**
 * What one bounded re-encryption pass did, in the only terms it is allowed to speak.
 *
 * Every number here is a count and every string is a name: key identifiers, definition identifiers, and
 * installation statuses. No plaintext, no ciphertext and no key material may appear in a rotation report,
 * because this is the value a console command prints to a terminal and a job hands to a log.
 *
 * `complete` is the field an operator acts on. A pass stops when its row budget runs out, so a large
 * installation needs several; `complete` is true only when the pass reached the end of every table it was
 * allowed to scan with budget still in hand, which is the point at which the previous key stops being
 * needed for live rows. `skipped` is why that is not the same as the point at which the previous key can
 * be deleted — an installation the pass could not fence is named there rather than quietly ignored.
 *
 * @since  2.0.0
 */
final readonly class RecordSecretRotationReport
{
    /**
     * Record the outcome of one pass.
     *
     * @param   string                                              $activeKeyId  Identifier every re-sealed
     *          envelope now carries.
     * @param   int                                                 $examined     Rows read because they still
     *          named another key.
     * @param   int                                                 $resealed     Rows re-sealed under the
     *          active key by this pass.
     * @param   int                                                 $superseded   Rows an ordinary writer had
     *          already replaced between the read and the guarded update; nothing was lost, and the row
     *          already carries the active key.
     * @param   int                                                 $definitions  Definitions whose installed
     *          tables declare at least one secret field and were scanned.
     * @param   list<array{definition_id: string, status: string}>  $skipped      Installations holding secret
     *          fields that this pass could not touch, each with the status that excluded it.
     * @param   bool                                                $complete     True when no stale row
     *          remained in any scanned table when the pass ended.
     *
     * @throws  InvalidArgumentException  When a count is negative or the re-sealed and superseded rows
     *          together exceed the rows examined.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $activeKeyId,
        public int $examined,
        public int $resealed,
        public int $superseded,
        public int $definitions,
        public array $skipped,
        public bool $complete,
    ) {
        if ($examined < 0 || $resealed < 0 || $superseded < 0 || $definitions < 0) {
            throw new InvalidArgumentException('A record secret rotation report cannot carry negative counts.');
        }
        if ($resealed + $superseded > $examined) {
            throw new InvalidArgumentException('A record secret rotation report accounts for more rows than it read.');
        }
    }
}
