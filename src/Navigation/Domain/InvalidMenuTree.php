<?php

declare(strict_types=1);

namespace Kumwe\App\Navigation\Domain;

use DomainException;

/**
 * Signals that a set of menu items does not form a valid tree.
 *
 * `MenuTree` refuses to exist in a broken shape, so this is raised at construction and on every move
 * rather than left for a renderer to trip over: duplicate item ids, a parent that is not in the set,
 * two siblings claiming the same slug, a parent cycle, or a move that would place an item below
 * itself. Each of those would otherwise produce a path that is ambiguous or impossible to reach, so
 * the exception marks a rejected edit and never a transient failure worth retrying.
 *
 * @since  2.0.0
 */
final class InvalidMenuTree extends DomainException
{
}
