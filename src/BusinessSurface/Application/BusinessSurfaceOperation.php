<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use Kumwe\App\BusinessSecurity\Application\FieldAccessUsage;

/**
 * Closed operation vocabulary shared by generated browser, REST, CLI and MCP adapters.
 *
 * @since  2.0.0
 */
enum BusinessSurfaceOperation: string
{
    /** Discover entity types. @since 2.0.0 */
    case Discover = 'discover';

    /** Browse a record collection. @since 2.0.0 */
    case Browse = 'browse';

    /** Read one record. @since 2.0.0 */
    case Read = 'read';

    /** Create a record. @since 2.0.0 */
    case Create = 'create';

    /** Update a record. @since 2.0.0 */
    case Update = 'update';

    /** Archive a record. @since 2.0.0 */
    case Archive = 'archive';

    /** Physically or softly delete a record. @since 2.0.0 */
    case Delete = 'delete';

    /** Restore an archived or soft-deleted record. @since 2.0.0 */
    case Restore = 'restore';

    /** Execute a declared record action. @since 2.0.0 */
    case Action = 'action';

    /** Read record revision history. @since 2.0.0 */
    case History = 'history';

    /** Read or change a relationship. @since 2.0.0 */
    case Relation = 'relation';

    /** Reorder an ordered relationship. @since 2.0.0 */
    case Reorder = 'reorder';

    /** Request or inspect approval. @since 2.0.0 */
    case Approval = 'approval';

    /** Run a bounded aggregate report. @since 2.0.0 */
    case Report = 'report';

    /** Produce a bounded export representation. @since 2.0.0 */
    case Export = 'export';

    /** Inspect a caller-bound operation outcome. @since 2.0.0 */
    case Status = 'status';

    /**
     * Capability enforced by the record service for this operation.
     *
     * @return  string  Stable dotted capability identifier.
     *
     * @since   2.0.0
     */
    public function capability(): string
    {
        return 'business.record.' . match ($this) {
            self::Discover => 'browse',
            self::Relation => 'relate',
            self::Reorder => 'relate',
            self::Approval => 'action',
            self::Status => 'read',
            default => $this->value,
        };
    }

    /**
     * Field-disclosure usage that describes metadata for this operation.
     *
     * @return  FieldAccessUsage  Exact read, write, query, report, or export use.
     *
     * @since   2.0.0
     */
    public function fieldUsage(): FieldAccessUsage
    {
        return match ($this) {
            self::Create => FieldAccessUsage::Create,
            self::Update => FieldAccessUsage::Update,
            self::Browse, self::Discover, self::Relation => FieldAccessUsage::List,
            self::History => FieldAccessUsage::Audit,
            self::Report => FieldAccessUsage::Report,
            self::Export => FieldAccessUsage::Export,
            default => FieldAccessUsage::Detail,
        };
    }
}
