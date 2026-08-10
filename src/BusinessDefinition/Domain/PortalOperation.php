<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * Closed set of business-record operations an entity may explicitly expose through the portal.
 *
 * The values name generated surface operations rather than capabilities. This distinction is deliberate:
 * sharing an underlying capability never lets an enabled relation editor silently enable reordering, or
 * an enabled action silently enable approval requests. Every portal operation is independently opted in.
 *
 * @since  2.0.0
 */
enum PortalOperation: string
{
    /**
     * Execute a definition-declared action, including an action wrapped in an approval request.
     *
     * @since  2.0.0
     */
    case Action = 'action';

    /**
     * Request or inspect maker-checker approval for an action.
     *
     * @since  2.0.0
     */
    case Approval = 'approval';

    /**
     * Mark a live record as archived.
     *
     * @since  2.0.0
     */
    case Archive = 'archive';

    /**
     * Browse a bounded, policy-filtered record collection.
     *
     * @since  2.0.0
     */
    case Browse = 'browse';

    /**
     * Create one record through its definition contract.
     *
     * @since  2.0.0
     */
    case Create = 'create';

    /**
     * Delete one record under its declared lifecycle behavior.
     *
     * @since  2.0.0
     */
    case Delete = 'delete';

    /**
     * Export an authorized record projection.
     *
     * @since  2.0.0
     */
    case Export = 'export';

    /**
     * Read immutable revision history for one record.
     *
     * @since  2.0.0
     */
    case History = 'history';

    /**
     * Read one policy-filtered record detail.
     *
     * @since  2.0.0
     */
    case Read = 'read';

    /**
     * Read, add, or remove a declared relationship.
     *
     * @since  2.0.0
     */
    case Relation = 'relation';

    /**
     * Replace the order of an explicitly ordered relationship.
     *
     * @since  2.0.0
     */
    case Reorder = 'reorder';

    /**
     * Run an authorized report or aggregate projection.
     *
     * @since  2.0.0
     */
    case Report = 'report';

    /**
     * Restore an archived or soft-deleted record when its lifecycle permits it.
     *
     * @since  2.0.0
     */
    case Restore = 'restore';

    /**
     * Inspect one caller-bound generated operation outcome.
     *
     * @since  2.0.0
     */
    case Status = 'status';

    /**
     * Apply an optimistic, validated patch to one record.
     *
     * @since  2.0.0
     */
    case Update = 'update';
}
