<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;

/**
 * Write-only port for selecting a Blueprint for one immutable Content type version.
 *
 * Kept separate from the AP-2 projection reader so model reads cannot acquire mutation authority.
 *
 * @since  2.0.0
 */
interface ContentBlueprintBindingStore
{
    /**
     * Insert the first and only initial binding for an exact type version.
     *
     * @param   ContentBlueprintBinding  $binding  Exact immutable type-version binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(ContentBlueprintBinding $binding): void;
}
