<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;

interface PublishedDefinitionSchemaObserver
{
    /**
     * Persists proposed plans for a complete published graph. It never executes DDL.
     *
     * @param list<DefinitionVersionRecord> $definitions
     * @return list<SchemaPlan>
     */
    public function observePublishedGraph(
        SiteContext $site,
        array $definitions,
        string $actorIdentifier,
        DateTimeImmutable $now,
    ): array;
}
