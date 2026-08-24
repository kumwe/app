<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Domain\Host\StudioHostSession;

/**
 * One resolved server-side session plus its current effective permissions, lifecycle authority and generation.
 *
 * @since  2.0.0
 */
final readonly class StudioHostSessionSnapshot
{
    /**
     * Capture the live authorization snapshot used for one host dispatch.
     *
     * @param  StudioHostSession  $session       Stored opaque-key binding.
     * @param  list<string>       $permissions   Sorted canonical Studio permissions.
     * @param  string             $generation    Generation recomputed from live authority.
     * @param  bool               $modeAllowed   Whether the exact stored mode remains authorized.
     * @param  bool               $canPublish    Whether this exact target can become published.
     * @param  bool               $canUnpublish  Whether this exact target can return to draft.
     *
     * @since  2.0.0
     */
    public function __construct(
        public StudioHostSession $session,
        public array $permissions,
        public string $generation,
        public bool $modeAllowed,
        public bool $canPublish,
        public bool $canUnpublish,
    ) {
    }
}
