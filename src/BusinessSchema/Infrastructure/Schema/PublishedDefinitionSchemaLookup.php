<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Infrastructure\Schema;

use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessSchema\Contract\DefinitionSchemaLookup;
use Kumwe\Context\Value\SiteContext;

/**
 * Answers the package compiler's target lookup from the App's definition catalog.
 *
 * `kumwe/business-schema` compiles a definition's relationship, entity-reference and ordered-line targets
 * by asking its `DefinitionSchemaLookup` port for the published version of each handle, pinned to the
 * version a repin hint names. The package infers no site and reads no catalog of its own, so this adapter
 * resolves that port through `BusinessDefinitionRepository`: the site identifier the compiler passes is the
 * one the calling service already authorized, and only a version the catalog publishes for that site comes
 * back. Authorization, transactions and persistence stay with the catalog and the services that compile.
 *
 * @since  2.0.0
 */
final readonly class PublishedDefinitionSchemaLookup implements DefinitionSchemaLookup
{
    /**
     * Wire the lookup to the catalog published versions are read from.
     *
     * @param  BusinessDefinitionRepository  $definitions  Catalog every target handle is resolved through.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessDefinitionRepository $definitions)
    {
    }

    /**
     * Load the published definition one handle names in a site, at the pinned version or the catalog head.
     *
     * @param   string  $siteIdentifier  Site the target must belong to, as the compiler received it.
     * @param   string  $handle          Namespaced handle of the relationship or reference target.
     * @param   ?int    $version         Version a repin hint pins, or null for the version the head publishes.
     *
     * @return  ?EntityTypeDefinition  The published version, or null when the site publishes no such
     *          definition or never published that version.
     *
     * @since   2.0.0
     */
    public function published(string $siteIdentifier, string $handle, ?int $version = null): ?EntityTypeDefinition
    {
        return $this->definitions
            ->published(SiteContext::fromString($siteIdentifier), $handle, $version)
            ?->definition;
    }
}
