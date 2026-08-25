<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use InvalidArgumentException;

/**
 * One bounded provider page plus proof that another authorized item exists.
 *
 * @since  2.0.0
 */
final readonly class StudioResourceSearchPage
{
    /**
     * Capture a provider result before the host port serializes the opaque cursor.
     *
     * @param  list<StudioResourceSearchItem>  $items    Authorized items in deterministic order.
     * @param  bool                            $hasNext  Whether another authorized item follows.
     *
     * @since  2.0.0
     */
    public function __construct(public array $items, public bool $hasNext)
    {
        foreach ($items as $item) {
            if (!$item instanceof StudioResourceSearchItem) {
                throw new InvalidArgumentException('A Studio resource search page contains an invalid item.');
            }
        }
    }
}
