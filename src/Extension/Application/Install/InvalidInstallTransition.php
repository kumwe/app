<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

use DomainException;

/**
 * Raised when an install plan is asked for a lifecycle change its current state does not allow.
 *
 * `AtomicInstallPlan` throws this for a second `start()`, a step reported out of its declared order, a
 * commit while actions are still outstanding, and a rollback begun from a state that is neither
 * executing nor failed. It reports a sequencing fault in the code driving the install, not a defect in
 * the package being installed — which is why it extends `DomainException` rather than a package or
 * trust failure. The plan is left untouched when this is thrown, so the caller can still read its state
 * and completed actions to decide whether to compensate.
 *
 * @since  2.0.0
 */
final class InvalidInstallTransition extends DomainException
{
}
