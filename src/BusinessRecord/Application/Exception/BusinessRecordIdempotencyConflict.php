<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

use InvalidArgumentException;

/**
 * Signals that a stored idempotency entry cannot satisfy the command presenting its key.
 *
 * A repeated command normally replays the entry `BusinessRecordIdempotencyRepository` holds for its
 * scope digest; this is what the record layer raises when that replay is impossible. Four states cover
 * it: the key was reused for a different request or a different authority; the repeat arrived after the
 * declared replay window closed; the first attempt has not finished; or the stored row cannot be
 * reconstituted and re-proved against its checksum. The state is folded into the stable code —
 * `business_record.idempotency_` followed by the state — so a delivery adapter can answer each case
 * differently without parsing the message. Unlike `BusinessRecordIdempotencyRace`, none of the four is
 * retried by the record layer itself: the caller decides, and for a reused key, an elapsed window or a
 * corrupt entry only a fresh key moves the command forward.
 *
 * `replay_window_elapsed` is separate from `key_reused` deliberately. A terminal reconnecting after a
 * long disconnection has done nothing wrong and its request is unchanged; what has run out is the
 * platform's promise to remember the outcome. Telling it so by name is what lets an operator reconcile
 * the work instead of discovering a second effect later, which is exactly what a shared code for
 * "something about this key is wrong" would have hidden.
 *
 * @since  2.0.0
 */
final class BusinessRecordIdempotencyConflict extends BusinessRecordException
{
    /**
     * Build the conflict for one of the three replay failures.
     *
     * @param   string  $state  Which failure this is: `key_reused`, `replay_window_elapsed`,
     *          `in_progress`, or `corrupt`. It picks both the operator message and the suffix of the
     *          stable code.
     *
     * @throws  InvalidArgumentException  When $state is none of the four supported values.
     *
     * @since   2.0.0
     */
    public function __construct(string $state)
    {
        if (!in_array($state, ['key_reused', 'replay_window_elapsed', 'in_progress', 'corrupt'], true)) {
            throw new InvalidArgumentException('The idempotency conflict state is unsupported.');
        }
        parent::__construct(
            'business_record.idempotency_' . $state,
            match ($state) {
                'key_reused' => 'The idempotency key was already used for a different request or authority.',
                'replay_window_elapsed' => 'The idempotency key is known but its replay window has closed, '
                    . 'so this command was refused rather than applied a second time.',
                'in_progress' => 'The idempotent business-record operation is still in progress.',
                'corrupt' => 'The stored idempotent business-record outcome failed integrity verification.',
            },
        );
    }
}
