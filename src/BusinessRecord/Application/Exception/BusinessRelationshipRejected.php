<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

/**
 * Raised when a relationship mutation contradicts the shape the definition declares for that relationship.
 *
 * `DoctrineBusinessRecordWriteRepository` reports every such refusal through this one type: line values
 * handed to a singular relationship, an owned line without its pinned line definition, embedded target
 * values on a relationship that is not an owned line, a position supplied for a singular inverse,
 * removal of a relationship the definition marks required, an unrelate aimed at a link that is not
 * there, a reorder of an unordered collection or of one whose storage the inverse side owns, a reorder
 * that does not list every current member exactly once, and a member that changed while the order was
 * being written.
 *
 * The stable code is the same for all of them, so the message is where the caller learns which rule it
 * hit; none of these are retryable without changing the request.
 *
 * @since  2.0.0
 */
final class BusinessRelationshipRejected extends BusinessRecordException
{
    /**
     * Report a refused relationship mutation under the `business_record.relationship_rejected` code.
     *
     * @param  string  $reason  Operator-facing sentence naming the relationship rule that refused the
     *         mutation; the default stands in only when a caller supplies nothing more specific.
     *
     * @since  2.0.0
     */
    public function __construct(string $reason = 'The requested business relationship mutation is invalid.')
    {
        parent::__construct('business_record.relationship_rejected', $reason);
    }
}
