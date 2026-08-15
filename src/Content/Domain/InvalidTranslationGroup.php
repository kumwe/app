<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use DomainException;

/**
 * Raised when a translation group would be assembled in a shape the delivery rules cannot honour.
 *
 * A translation group is read on the public request path to decide which `hreflang` links are emitted
 * and which locales the language selector offers, so a group that is internally inconsistent — two
 * entries claiming the same locale, a declared fallback naming a locale that has no entry, two locales
 * sharing one route segment — has to fail where it is built rather than where it is rendered. The
 * message names the rule that was broken so an editor can be told which locale is at fault.
 *
 * @since  2.0.0
 */
final class InvalidTranslationGroup extends DomainException
{
}
