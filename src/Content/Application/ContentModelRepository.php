<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Kumwe\CMS\Workflow\Domain\WorkflowDefinition;

interface ContentModelRepository
{
    /** @return list<ContentTypeDefinition> */
    public function contentTypes(SiteContext $site): array;

    public function contentType(SiteContext $site, string $identifier, ?int $version = null): ?ContentTypeDefinition;

    public function insertContentType(ContentTypeDefinition $definition): void;

    public function publishContentType(ContentTypeDefinition $definition, int $expectedVersion): void;

    /** @return list<WorkflowDefinition> */
    public function workflows(SiteContext $site): array;

    public function workflow(SiteContext $site, string $identifier, ?int $version = null): ?WorkflowDefinition;

    public function insertWorkflow(WorkflowDefinition $definition): void;

    public function publishWorkflow(WorkflowDefinition $definition, int $expectedVersion): void;
}
