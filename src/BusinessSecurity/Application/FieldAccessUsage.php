<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application;

/**
 * Distinct ways an actor may use a business-record field.
 *
 * Keeping query uses separate prevents permission to see a value from silently granting permission to
 * recover it through filtering, ordering, search, or aggregates.
 *
 * @since  2.0.0
 */
enum FieldAccessUsage: string
{
    /** Submit the field while creating a record. @since 2.0.0 */
    case Create = 'create';

    /** Submit the field while changing a record. @since 2.0.0 */
    case Update = 'update';

    /** Disclose the field on a single-record detail. @since 2.0.0 */
    case Detail = 'detail';

    /** Disclose the field in a collection or relation list. @since 2.0.0 */
    case List = 'list';

    /** Use the field in an exact query predicate. @since 2.0.0 */
    case Filter = 'filter';

    /** Use the field in full-text-like search. @since 2.0.0 */
    case Search = 'search';

    /** Use the field to order results. @since 2.0.0 */
    case Sort = 'sort';

    /** Use the field in a report aggregate. @since 2.0.0 */
    case Aggregate = 'aggregate';

    /** Disclose the field in report rows or grouping output. @since 2.0.0 */
    case Report = 'report';

    /** Disclose the field through export. @since 2.0.0 */
    case Export = 'export';

    /** Disclose the field in history and audit differences. @since 2.0.0 */
    case Audit = 'audit';

    /** Disclose the field through the bounded generic MCP record surface. @since 2.0.0 */
    case Mcp = 'mcp';

    /** Use the field to select records through a relationship predicate. @since 2.0.0 */
    case Relation = 'relation';

    /** Disclose the field inside an included related-record projection. @since 2.0.0 */
    case Include = 'include';

    /** Disclose an identity field while another record points at its owner. @since 2.0.0 */
    case PublicReference = 'public_reference';
}
