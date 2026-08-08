<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel;

use Closure;
use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaLifecycleObserver;
use Kumwe\CMS\BusinessSchema\Application\PublishedDefinitionSchemaObserver;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;

/**
 * Breaks the composition-time cycle between the trusted extension registry and
 * schema compilation without hiding either runtime dependency from ContainerFactory.
 */
final readonly class DeferredBusinessSchemaObserver implements
    PublishedDefinitionSchemaObserver,
    BusinessSchemaLifecycleObserver
{
    /**
     * @param Closure(): PublishedDefinitionSchemaObserver $publication
     * @param Closure(): BusinessSchemaLifecycleObserver $lifecycle
     */
    public function __construct(
        private Closure $publication,
        private Closure $lifecycle,
    ) {
    }

    /** @param list<DefinitionVersionRecord> $definitions @return list<SchemaPlan> */
    public function observePublishedGraph(
        SiteContext $site,
        array $definitions,
        string $actorIdentifier,
        DateTimeImmutable $now,
    ): array {
        return ($this->publication)()->observePublishedGraph(
            $site,
            $definitions,
            $actorIdentifier,
            $now,
        );
    }

    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $at): void
    {
        ($this->lifecycle)()->setOwnerActive($ownerIdentifier, $active, $at);
    }
}
