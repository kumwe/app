<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Application\Approval;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Redacted immutable decision shown in an authorized approval detail view.
 *
 * @since  2.0.0
 */
final readonly class ApprovalVoteView
{
    /**
     * Create one validated and redacted approval decision projection.
     *
     * @param  string             $id          Vote UUID.
     * @param  string             $approverId  Accountable approver identity.
     * @param  string             $decision    Closed `approve` or `reject` decision.
     * @param  ?string            $reason      Optional bounded operator note.
     * @param  DateTimeImmutable  $decidedAt   Decision instant.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $approverId,
        public string $decision,
        public ?string $reason,
        public DateTimeImmutable $decidedAt,
    ) {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('An approval view decision is invalid.');
        }
    }
}
