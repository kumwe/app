<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use stdClass;

/**
 * One owner-bound snapshot of the active Studio composition catalog and renderability metadata.
 *
 * @since  2.0.0
 */
final readonly class StudioCompositionContributionProjection
{
    /**
     * Capture one deterministic active contribution snapshot for browser bootstrap or provisioning.
     *
     * @param  list<stdClass>         $documents       Canonical contribution documents.
     * @param  array<string, string>  $owners          Trusted package owner by kind-scoped document identity.
     * @param  list<stdClass>         $blockLocks      Exact supported block locks in deterministic type order.
     * @param  array<string, string>  $blockRenderers  Trusted renderer identifier by exact block type.
     *
     * @since  2.0.0
     */
    public function __construct(
        public array $documents,
        public array $owners,
        public array $blockLocks,
        public array $blockRenderers,
    ) {
    }
}
