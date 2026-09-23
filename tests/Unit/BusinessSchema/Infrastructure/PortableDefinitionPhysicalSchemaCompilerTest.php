<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSchema\Infrastructure;

use Kumwe\App\BusinessSchema\Infrastructure\Schema\PortableDefinitionPhysicalSchemaCompiler;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessSchema\Compiler\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\BusinessSchema\Contract\DefinitionSchemaLookup;
use Kumwe\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Host adapter binding the App schema-compiler port to the portable package compiler.
 *
 * The package compiles under a bare site identifier while the App services keep passing the `SiteContext`
 * they authorized; the adapter owes them a blueprint identical to the package's own, and it must let the
 * package's site refusal through rather than compile a definition into a site it does not belong to.
 *
 * @since  2.0.0
 */
#[CoversClass(PortableDefinitionPhysicalSchemaCompiler::class)]
final class PortableDefinitionPhysicalSchemaCompilerTest extends TestCase
{
    /**
     * The blueprint compiled through the adapter is the package blueprint for the site's identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlueprintIsCompiledUnderTheSiteIdentifierAndReturnedUnchanged(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::document())->published(1);
        $compiler = $this->compiler();

        $blueprint = (new PortableDefinitionPhysicalSchemaCompiler($compiler))
            ->compile($definition, SiteContext::fromString('default'));

        self::assertSame($compiler->compile($definition, 'default')->checksum(), $blueprint->checksum());
        self::assertSame($definition->id, $blueprint->definitionId);
        self::assertSame($definition->definitionVersion, $blueprint->definitionVersion);
        self::assertNotNull($blueprint->table('record'));
    }

    /**
     * A definition compiled for a site it does not belong to is refused by the package through the adapter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADefinitionOfAnotherSiteIsRefused(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::document())->published(1);
        $adapter = new PortableDefinitionPhysicalSchemaCompiler($this->compiler());

        $this->expectException(InvalidBusinessSchema::class);
        $adapter->compile($definition, SiteContext::fromString('other'));
    }

    /**
     * Build the package compiler over a catalog that publishes nothing, which the standalone fixture never asks.
     *
     * @return  CanonicalDefinitionPhysicalSchemaCompiler  Portable compiler with the built-in field types.
     *
     * @since   2.0.0
     */
    private function compiler(): CanonicalDefinitionPhysicalSchemaCompiler
    {
        return new CanonicalDefinitionPhysicalSchemaCompiler(
            $this->createStub(DefinitionSchemaLookup::class),
            new FieldTypeRegistry(),
            new PhysicalNameCompiler('kumwe_'),
        );
    }
}
