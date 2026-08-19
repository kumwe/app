<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Presentation\Preference;

use InvalidArgumentException;

/**
 * Bounded deterministic canonical access-group catalogue with explicit overflow evidence.
 *
 * Repositories inspect one extra row so callers can distinguish a complete result from a bounded prefix.
 * The extra row is evidence only: callers must not compose an effective multi-group value from `groups`
 * when it is present, because a prefix cannot preserve the union semantics of omitted groups.
 *
 * @since  2.0.0
 */
final readonly class PresentationAccessGroupCatalog
{
    /**
     * Validate one bounded access-group page and its completeness signal.
     *
     * @param   list<PresentationAccessGroup>  $groups     Canonical groups in deterministic display order.
     * @param   ?PresentationAccessGroup       $lookahead  First canonical row beyond the requested bound.
     *
     * @throws  InvalidArgumentException  When the page is unbounded, malformed, duplicated, or unordered.
     *
     * @since   2.0.0
     */
    public function __construct(public array $groups, public ?PresentationAccessGroup $lookahead)
    {
        if (!array_is_list($groups) || count($groups) > 250) {
            throw new InvalidArgumentException('A presentation access-group catalogue must be a bounded list.');
        }
        $previous = null;
        $seen = [];
        foreach ($groups as $group) {
            if (!$group instanceof PresentationAccessGroup || isset($seen[$group->id])) {
                throw new InvalidArgumentException('A presentation access-group catalogue contains an invalid row.');
            }
            $order = [$group->code, $group->roleId];
            if ($previous !== null && $previous > $order) {
                throw new InvalidArgumentException('A presentation access-group catalogue is not ordered.');
            }
            $seen[$group->id] = true;
            $previous = $order;
        }
        if ($lookahead !== null) {
            $order = [$lookahead->code, $lookahead->roleId];
            if (isset($seen[$lookahead->id]) || ($previous !== null && $previous > $order)) {
                throw new InvalidArgumentException('A presentation access-group lookahead row is invalid.');
            }
        }
    }

    /**
     * Report whether a later canonical row was read beyond the requested bound.
     *
     * @return  bool  True only when `lookahead` is present.
     *
     * @since   2.0.0
     */
    public function hasNext(): bool
    {
        return $this->lookahead !== null;
    }
}
