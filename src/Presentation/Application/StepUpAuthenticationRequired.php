<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use DomainException;

/**
 * Signals that a theme change was refused because the actor did not re-prove who they are.
 *
 * Capability grants alone are not enough to swap the theme that renders the back office: a hijacked
 * session would otherwise be able to replace every administrative screen. `ThemeActivationGuard` asks
 * for the actor's current password at the moment of the change and raises this when the credential is
 * absent, wrong, or offered by a context with no human principal behind it. Delivery code distinguishes
 * it from an ordinary authorization failure so it can answer with a step-up challenge instead of a flat
 * refusal.
 *
 * @since  2.0.0
 */
final class StepUpAuthenticationRequired extends DomainException
{
}
