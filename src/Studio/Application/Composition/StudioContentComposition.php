<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use stdClass;

/**
 * Authorized exact model, binding and current Blueprint head used by the composition surface.
 *
 * @since  2.0.0
 */
final readonly class StudioContentComposition
{
    /**
     * Capture the authorized model and exact bound Blueprint head.
     *
     * @param  stdClass                 $model      Authorized AP-2 Content model projection.
     * @param  ContentBlueprintBinding  $binding    Host-owned type-version binding.
     * @param  StoredStudioArtifact     $blueprint  Exact admitted Blueprint artifact.
     *
     * @since  2.0.0
     */
    public function __construct(
        public stdClass $model,
        public ContentBlueprintBinding $binding,
        public StoredStudioArtifact $blueprint,
    ) {
    }
}
