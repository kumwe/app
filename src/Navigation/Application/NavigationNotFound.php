<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Application;

use DomainException;

/**
 * Signals that a menu or menu item the caller named does not exist.
 *
 * `NavigationService` reads through the repository's nullable lookups and converts the null into this
 * exception, so callers never have to distinguish "no such record" from "not readable": an actor who
 * may not see a record is refused with an authorization failure before the lookup runs. Delivery code
 * maps it to a 404, and its message is written for an operator rather than naming the missing id.
 *
 * @since  2.0.0
 */
final class NavigationNotFound extends DomainException
{
}
