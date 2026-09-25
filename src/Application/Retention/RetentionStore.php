<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

/**
 * The hot stores whose growth the retention catalogue governs, named as operators see them.
 *
 * Every value is a ledger that an ordinary workload appends to on every transaction or delivery and
 * that nothing else ever shrinks. The capacity contract's `retention_budget_rule.applies_to` list is
 * the source of the set: each of its entries maps onto one case here, so a store cannot be appended
 * to without also being declared, budgeted, observed and drained. The backing value is the label the
 * retention metrics carry, which is why it is a closed enumeration rather than free text.
 *
 * @since  2.0.0
 */
enum RetentionStore: string
{
    /**
     * Business-record command idempotency claims, one per typed record mutation.
     *
     * @since  2.0.0
     */
    case BusinessIdempotency = 'business_idempotency';

    /**
     * Delivery and API idempotency records, one per keyed HTTP or machine mutation.
     *
     * @since  2.0.0
     */
    case DeliveryIdempotency = 'delivery_idempotency';

    /**
     * Business-record revisions and their line revision detail.
     *
     * @since  2.0.0
     */
    case Revisions = 'revisions';

    /**
     * Integration outbox rows, the committed source of every durable event.
     *
     * @since  2.0.0
     */
    case OutboxSourceEvents = 'outbox_source_events';

    /**
     * The sequenced projection journal that dispatchers and projections consume in order.
     *
     * @since  2.0.0
     */
    case SequencedJournal = 'sequenced_journal';

    /**
     * Independent consumer and webhook receipts, one per consumer and event.
     *
     * @since  2.0.0
     */
    case InboxReceipts = 'inbox_receipts';

    /**
     * Settled queue jobs, their attempts and the dead-letter ledger.
     *
     * @since  2.0.0
     */
    case JobHistory = 'job_history';

    /**
     * Settled long-running process work items.
     *
     * @since  2.0.0
     */
    case ProcessHistory = 'process_history';

    /**
     * Report export artifacts past their download expiry.
     *
     * @since  2.0.0
     */
    case ExportArtifacts = 'export_artifacts';

    /**
     * The tamper-evident audit trail and its anchor ledger.
     *
     * @since  2.0.0
     */
    case Audit = 'audit';

    /**
     * Expired administrator and portal sessions, step-up proofs and tokens.
     *
     * @since  2.0.0
     */
    case Sessions = 'sessions';
}
