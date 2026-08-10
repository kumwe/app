<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Domain;

/**
 * Quantifier applied when a report filter crosses one declared relationship.
 *
 * @since  2.0.0
 */
enum ReportRelationQuantifier: string
{
    /** At least one related row matches. @since 2.0.0 */
    case Any = 'any';
    /** No related row matches. @since 2.0.0 */
    case None = 'none';
    /** Every related row matches. @since 2.0.0 */
    case All = 'all';
}
