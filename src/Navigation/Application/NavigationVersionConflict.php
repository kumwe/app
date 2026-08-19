<?php

declare(strict_types=1);

namespace Kumwe\App\Navigation\Application;

use DomainException;

/**
 * Signals that a navigation write lost the optimistic-locking race and was refused.
 *
 * Every menu and item update, delete and cascading path move quotes the `version` the caller read.
 * When the stored row no longer carries that version — because another editor saved first, or because
 * the row vanished — the write is rejected rather than applied blind, which keeps two administrator
 * screens from silently overwriting each other's tree. The remedy is always the same: reload the
 * record and retry, so delivery code answers with a conflict status rather than a retry loop.
 *
 * @since  2.0.0
 */
final class NavigationVersionConflict extends DomainException
{
}
