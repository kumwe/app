<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Plan;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A minted change plan that describes work without carrying any means of performing it.
 *
 * `POST /api/v1/plans` hands one of these back so an automation client can say what it intends before
 * a human approves it. The safety is structural rather than a flag to be trusted: the value object
 * holds an operation, a target and a validity window and nothing else, so there is no apply step to
 * reach for, and `toArray()` states as much with `mode: plan_only` and `apply_supported: false`. The
 * constructor is the enforcement point — no plan can exist with an identifier that is not a canonical
 * UUIDv7, a target that is blank, overlong or carries control characters, or a window that expires
 * before it opens.
 *
 * @since  2.0.0
 */
final readonly class SafePlan
{
    /**
     * Mint a plan, refusing any identifier, target or validity window that is not well formed.
     *
     * @param   string             $id         Canonical UUIDv7 naming this plan; the route's `ETag` is
     *          built from it.
     * @param   SafePlanOperation  $operation  Which review the plan describes.
     * @param   string             $target     What the review is about, as the caller named it: 1 to 255
     *          bytes of printable text.
     * @param   DateTimeImmutable  $createdAt  When the plan was minted.
     * @param   DateTimeImmutable  $expiresAt  When the plan stops being valid; must fall after `$createdAt`.
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical UUIDv7, when the target
     *          is blank, longer than 255 bytes or carries a control character, or when the expiry does
     *          not follow the creation time.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $id,
        private SafePlanOperation $operation,
        private string $target,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) !== 1) {
            throw new InvalidArgumentException('A safe plan ID must be a canonical UUIDv7.');
        }

        if (trim($target) === '' || strlen($target) > 255 || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            throw new InvalidArgumentException('A safe plan target must contain 1 to 255 printable characters.');
        }

        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A safe plan must expire after it is created.');
        }
    }

    /**
     * Return the identifier this plan was minted under.
     *
     * @return  string  Canonical UUIDv7, so identifiers sort in the order the plans were minted.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Export the plan in the JSON shape `POST /api/v1/plans` returns.
     *
     * `mode` and `apply_supported` are constants rather than state: they are what tells a client that
     * no apply step exists for this document. The `steps` list names the read-only stages a reviewer
     * works through and is the same for every operation, so it describes the shape of a review rather
     * than anything computed from the target.
     *
     * @return  array<string, bool|string|list<string>>  Members keyed as the API spells them, with the
     *          timestamps rendered as RFC 3339.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mode' => 'plan_only',
            'operation' => $this->operation->value,
            'target' => $this->target,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'steps' => [
                'read_current_state',
                'evaluate_requested_review',
                'describe_proposed_changes',
            ],
            'apply_supported' => false,
        ];
    }
}
