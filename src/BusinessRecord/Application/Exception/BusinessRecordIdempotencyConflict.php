<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

use InvalidArgumentException;

/**
 * Signals that a stored idempotency entry cannot satisfy the command presenting its key.
 *
 * A repeated command normally replays the entry `BusinessRecordIdempotencyRepository` holds for its
 * scope digest; this is what the record layer raises when that replay is impossible. Three states
 * cover it: the key was reused for a different request or authority, or after the entry expired; the
 * first attempt has not finished; or the stored row cannot be reconstituted and re-proved against its
 * checksum. The state is folded into the stable code — `business_record.idempotency_` followed by the
 * state — so a delivery adapter can answer each case differently without parsing the message. Unlike
 * `BusinessRecordIdempotencyRace`, none of the three is retried by the record layer itself: the
 * caller decides, and for a reused key or a corrupt entry only a fresh key moves the command forward.
 *
 * @since  2.0.0
 */
final class BusinessRecordIdempotencyConflict extends BusinessRecordException
{
    /**
     * Build the conflict for one of the three replay failures.
     *
     * @param   string  $state  Which failure this is: `key_reused`, `in_progress`, or `corrupt`. It
     *          picks both the operator message and the suffix of the stable code.
     *
     * @throws  InvalidArgumentException  When $state is none of the three supported values.
     *
     * @since   2.0.0
     */
    public function __construct(string $state)
    {
        if (!in_array($state, ['key_reused', 'in_progress', 'corrupt'], true)) {
            throw new InvalidArgumentException('The idempotency conflict state is unsupported.');
        }
        parent::__construct(
            'business_record.idempotency_' . $state,
            match ($state) {
                'key_reused' => 'The idempotency key was already used for a different request or authority.',
                'in_progress' => 'The idempotent business-record operation is still in progress.',
                'corrupt' => 'The stored idempotent business-record outcome failed integrity verification.',
            },
        );
    }
}
