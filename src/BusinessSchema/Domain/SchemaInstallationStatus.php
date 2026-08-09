<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

/**
 * Availability state of one definition's installed physical schema on a site.
 *
 * This is the gate the record runtime consults before it will touch generated tables: only `Active`
 * admits ordinary record commands, while the remaining states keep physical data and history intact but
 * fail record traffic closed. The executor and the extension lifecycle move an installation between these
 * states; the state never says whether the tables exist, only whether they may be used.
 *
 * @since  2.0.0
 */
enum SchemaInstallationStatus: string
{
    /**
     * Physical tables are mid-change under an executing plan, so no record command may cross them.
     *
     * @since  2.0.0
     */
    case Installing = 'installing';

    /**
     * The installed tables match their blueprint checksum and record commands may run against them.
     *
     * @since  2.0.0
     */
    case Active = 'active';

    /**
     * Retained intact after its owning extension was disabled from an active installation.
     *
     * @since  2.0.0
     */
    case Disabled = 'disabled';

    /**
     * Retained intact after an in-flight installation or upgrade was interrupted by owner disablement.
     *
     * A plan finalized while its owner is inactive also lands here rather than in `Active`, so an operator
     * re-activates only after the compatibility and introspection checks pass.
     *
     * @since  2.0.0
     */
    case Preserved = 'preserved';

    /**
     * Kept for diagnosis after installation could not be completed, and never marked as preserved.
     *
     * @since  2.0.0
     */
    case Failed = 'failed';
}
