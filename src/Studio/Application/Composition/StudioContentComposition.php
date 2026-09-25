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

    /**
     * Project the composition for machine callers: coordinates, model, binding and the exact Blueprint head.
     *
     * Documents stay decoded objects, so the JSON a REST, console or MCP caller receives is the canonical
     * artifact document byte for byte in meaning, with empty objects kept distinct from empty lists.
     *
     * @return  array{
     *            content_type_id: string, content_type_version: int, binding_revision: int, model: stdClass,
     *            blueprint: array{
     *              id: string, version: string, kind: string, revision: string, status: string,
     *              document: stdClass, dependencies: list<stdClass>
     *            }
     *          }  Machine document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'content_type_id' => $this->binding->contentTypeId,
            'content_type_version' => $this->binding->contentTypeVersion,
            'binding_revision' => $this->binding->revision,
            'model' => $this->model,
            'blueprint' => [
                'id' => $this->blueprint->id,
                'version' => $this->blueprint->version,
                'kind' => $this->blueprint->kind,
                'revision' => $this->blueprint->revision,
                'status' => $this->blueprint->status,
                'document' => $this->blueprint->document(),
                'dependencies' => $this->blueprint->dependencies(),
            ],
        ];
    }
}
