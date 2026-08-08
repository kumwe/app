<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

use InvalidArgumentException;

final class BusinessRecordIdempotencyConflict extends BusinessRecordException
{
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
