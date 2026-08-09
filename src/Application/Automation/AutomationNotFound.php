<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DomainException;

/**
 * Signals that the schedule the caller named is not there to be acted on.
 *
 * `AutomationManagementService` raises it where the repository's lookup comes back null, which is the
 * one case that means genuinely absent: a schedule the actor may not manage is found and then refused
 * by the repository, so that outcome surfaces as `AuthorizationDenied` and never arrives here.
 * `AutomationApiHandler` renders this as a 404 problem document, and the message stays operator-facing
 * rather than repeating the identifier that was asked for.
 *
 * @since  2.0.0
 */
final class AutomationNotFound extends DomainException
{
}
