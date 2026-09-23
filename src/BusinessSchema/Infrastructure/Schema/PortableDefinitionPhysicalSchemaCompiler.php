<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Infrastructure\Schema;

use Kumwe\App\BusinessSchema\Application\DefinitionPhysicalSchemaCompiler;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessSchema\Compiler\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\Context\Value\SiteContext;

/**
 * Binds the App's schema-compiler port to the portable compiler `kumwe/business-schema` ships.
 *
 * The package compiler is a pure function of a definition and a site identifier: it implements no App port
 * and receives its catalog lookup, field-type resolver and name compiler as explicit host bindings. The
 * planner, executor and lifecycle manager keep asking the `DefinitionPhysicalSchemaCompiler` port with the
 * `SiteContext` they authorized, so this adapter hands the package that site's identifier and returns the
 * blueprint unchanged, which keeps every approval checksum a function of exactly the inputs it was before.
 *
 * @since  2.0.0
 */
final readonly class PortableDefinitionPhysicalSchemaCompiler implements DefinitionPhysicalSchemaCompiler
{
    /**
     * Wire the adapter to the package compiler the container builds from the host bindings.
     *
     * @param  CanonicalDefinitionPhysicalSchemaCompiler  $compiler  Portable compiler resolved through the
     *         package factory.
     *
     * @since  2.0.0
     */
    public function __construct(private CanonicalDefinitionPhysicalSchemaCompiler $compiler)
    {
    }

    /**
     * Compile the physical tables one published definition version installs in the given site.
     *
     * @param   EntityTypeDefinition  $definition  Published definition version to compile; never a draft.
     * @param   SiteContext           $site        Site owning the definition and scoping the generated names.
     *
     * @return  PhysicalSchemaBlueprint  Every table the version needs, checksummed for later comparison.
     *
     * @throws  \Kumwe\BusinessSchema\Domain\InvalidBusinessSchema  When the definition belongs to another
     *          site, carries no published version number, or a relationship target cannot be resolved.
     *
     * @since   2.0.0
     */
    public function compile(EntityTypeDefinition $definition, SiteContext $site): PhysicalSchemaBlueprint
    {
        return $this->compiler->compile($definition, $site->identifier());
    }
}
