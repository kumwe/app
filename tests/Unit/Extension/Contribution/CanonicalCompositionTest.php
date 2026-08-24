<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\CanonicalCompositionDocument;
use Kumwe\App\Extension\Contribution\CanonicalCompositionKind;
use Kumwe\App\Extension\Contribution\CanonicalCompositionRegistrar;
use Kumwe\App\Extension\Contribution\CompositionFieldControlDeclaration;
use Kumwe\App\Extension\Contribution\CompositionHostBinding;
use Kumwe\App\Extension\Contribution\CompositionPropertySchema;
use Kumwe\App\Extension\Contribution\CompositionPropertyType;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\Manifest5AdapterResult;
use Kumwe\App\Extension\Contribution\Manifest5CompositionAdapter;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Contribution\OwnedExtensionContributionRegistrar;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Contract\SchemaPropertyProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Proves the manifest 6 / SPI 4 canonical composition contract behaves exactly as issue 104 demands.
 *
 * Schema 6 carries canonical Studio documents in their exact byte form beside separate bounded host
 * bindings, on an additive registrar. These tests hold the whole seam: a valid schema-6 section
 * parses, manifest 6 accepts only SPI 4 and earlier schemas refuse SPI 4, a non-canonical string,
 * a foreign identity, an unbound block definition and a schema-invalid document are each refused
 * atomically, the manifest-5 adapter translates only lossless mappings and names everything else,
 * and the SPI-4 registrar keeps the exact pinned signature a published package compiles against.
 *
 * @since  2.0.0
 */
#[CoversClass(CanonicalCompositionDocument::class)]
#[CoversClass(CanonicalCompositionKind::class)]
#[CoversClass(CompositionHostBinding::class)]
#[CoversClass(ContributionOwner::class)]
#[CoversClass(Manifest5AdapterResult::class)]
#[CoversClass(Manifest5CompositionAdapter::class)]
#[CoversClass(ManifestContributionSet::class)]
#[CoversClass(OwnedExtensionContributionRegistrar::class)]
final class CanonicalCompositionTest extends TestCase
{
    /**
     * A schema-6 section with a valid canonical block and bindings parses and round-trips its wire shape.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASchemaSixSectionParsesItsCanonicalDocuments(): void
    {
        $set = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/shop'),
            $this->contributions(),
            6,
        );

        self::assertCount(1, $set->canonicalCompositionDocuments());
        self::assertCount(1, $set->compositionHostBindings());
        $document = $set->canonicalCompositionDocuments()[0];
        self::assertSame(CanonicalCompositionKind::BlockDefinition, $document->kind);
        self::assertSame('acme.shop/grid', $document->identity());
        self::assertSame('block-definition acme.shop/grid', $document->identifier());
        self::assertSame($document->canonical, CanonicalJson::stringify($document->document));
        $exported = $set->toArray();
        self::assertSame(
            ['kind' => 'block-definition', 'canonical' => $document->canonical],
            $exported['composition']['documents'][0] ?? null,
        );
        self::assertSame(
            $exported,
            ManifestContributionSet::fromManifest(
                ExtensionIdentifier::fromString('acme/shop'),
                $exported,
                6,
            )->toArray(),
        );
    }

    /**
     * Manifest 6 accepts only SPI 4, and an earlier manifest refuses SPI 4.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSchemaAndSpiRefuseEachOtherAcrossTheGenerationBoundary(): void
    {
        $wrongSpi = $this->contributions();
        $wrongSpi['version'] = 3;
        try {
            ManifestContributionSet::fromManifest(ExtensionIdentifier::fromString('acme/shop'), $wrongSpi, 6);
            self::fail('Manifest 6 must accept only SPI 4.');
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString('SPI version 4', $refusal->getMessage());
        }

        $earlierSchema = $this->contributions();
        $earlierSchema['version'] = 4;
        unset($earlierSchema['composition']);
        try {
            ManifestContributionSet::fromManifest(ExtensionIdentifier::fromString('acme/shop'), $earlierSchema, 5);
            self::fail('An earlier manifest must refuse SPI 4.');
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString('SPI version 3', $refusal->getMessage());
        }
    }

    /**
     * Each invalid schema-6 artifact rejects the owning contribution set atomically.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidCanonicalArtifactsAreRefusedAtomically(): void
    {
        $document = $this->blockDocument();
        $mutations = [
            'non-canonical bytes' => static function (array $contributions) use ($document): array {
                $contributions['composition']['documents'][0]['canonical']
                    = (string) json_encode($document, JSON_PRETTY_PRINT);

                return $contributions;
            },
            'foreign identity' => static function (array $contributions) use ($document): array {
                $foreign = clone $document;
                $foreign->type = 'other.vendor/grid';
                $contributions['composition']['documents'][0]['canonical'] = CanonicalJson::stringify($foreign);
                $contributions['composition']['host_bindings'][0]['id'] = 'other.vendor/grid';

                return $contributions;
            },
            'spoofed embedded owner' => static function (array $contributions) use ($document): array {
                $spoofed = clone $document;
                $spoofed->owner = (object) ['id' => 'studio.core/blocks', 'version' => '1.0.0'];

                return self::withDocument($contributions, CanonicalJson::stringify($spoofed));
            },
            'block without a renderer binding' => static function (array $contributions): array {
                $contributions['composition']['host_bindings'] = [];

                return $contributions;
            },
            'schema-invalid document' => static function (array $contributions) use ($document): array {
                $broken = clone $document;
                unset($broken->version);
                $contributions['composition']['documents'][0]['canonical'] = CanonicalJson::stringify($broken);

                return $contributions;
            },
            'profile-invalid propertySchema' => static function (array $contributions) use ($document): array {
                $open = clone $document;
                $open->propertySchema = json_decode(
                    '{"type":"object","additionalProperties":false,"properties":{"a":{"format":"email"}}}',
                    false,
                );

                return self::withDocument($contributions, CanonicalJson::stringify($open));
            },
        ];

        foreach ($mutations as $label => $mutate) {
            try {
                ManifestContributionSet::fromManifest(
                    ExtensionIdentifier::fromString('acme/shop'),
                    $mutate($this->contributions()),
                    6,
                );
                self::fail(sprintf('A set with %s must be refused.', $label));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * The manifest-5 adapter translates lossless property maps and admits what it produces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheManifestFiveAdapterTranslatesOnlyLosslessMappings(): void
    {
        $lossless = Manifest5CompositionAdapter::adaptPropertySchema(new CompositionPropertySchema([
            'heading' => ['type' => 'string', 'required' => true, 'maximum_length' => 120],
            'body' => ['type' => 'text', 'required' => false, 'maximum_length' => 4000],
            'columns' => ['type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 4],
            'emphasis' => ['type' => 'choice', 'required' => true, 'values' => ['muted', 'strong']],
            'visible' => ['type' => 'boolean', 'required' => false],
        ]));
        self::assertSame([], $lossless->unresolved);
        self::assertInstanceOf(stdClass::class, $lossless->schema);
        self::assertSame('object', $lossless->schema->type);
        self::assertFalse($lossless->schema->additionalProperties);
        self::assertSame(['emphasis', 'heading'], $lossless->schema->required);
        self::assertSame(120, $lossless->schema->properties->heading->maxLength);
        self::assertSame(['muted', 'strong'], $lossless->schema->properties->emphasis->enum);
        $validator = SchemaPropertyProfile::admit($lossless->schema);
        self::assertTrue($validator->validate(json_decode('{"heading":"x","emphasis":"muted"}', false)));

        $hostReference = Manifest5CompositionAdapter::adaptPropertySchema(new CompositionPropertySchema([
            'illustration' => ['type' => 'reference', 'required' => false, 'kind' => 'media'],
        ]));
        self::assertNull($hostReference->schema, 'A host reference must never be widened into a schema.');
        self::assertCount(1, $hostReference->unresolved);
        self::assertStringContainsString('media', $hostReference->unresolved[0]);
    }

    /**
     * The SPI-4 registrar keeps the exact pinned surface a published package compiles against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCanonicalRegistrarMatchesItsPinnedFixture(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 3) . '/Fixtures/ExtensionApi/canonical-composition-registrar-v1.json',
            ),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($fixture);

        $expected = $fixture['interfaces'][CanonicalCompositionRegistrar::class] ?? null;
        self::assertIsArray($expected);
        $actual = [];
        foreach (
            (new ReflectionClass(CanonicalCompositionRegistrar::class))
                ->getMethods(ReflectionMethod::IS_PUBLIC) as $method
        ) {
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $parameters[] = (string) $parameter->getType() . ' $' . $parameter->getName();
            }
            $actual[] = sprintf(
                '%s(%s): %s',
                $method->getName(),
                implode(', ', $parameters),
                (string) $method->getReturnType(),
            );
        }
        self::assertSame($expected, $actual, 'The pinned SPI-4 registrar surface changed.');

        $cases = array_map(
            static fn (CanonicalCompositionKind $case): string => $case->value,
            CanonicalCompositionKind::cases(),
        );
        self::assertSame($fixture['enums'][CanonicalCompositionKind::class] ?? null, $cases);
        self::assertSame(['documents', 'host_bindings'], $fixture['section_keys'] ?? null);
        self::assertTrue(is_a(OwnedExtensionContributionRegistrar::class, CanonicalCompositionRegistrar::class, true));
    }

    /**
     * A valid schema-6 contributions section with one canonical block and its bindings.
     *
     * @return  array<string, mixed>  The decoded `contributions` value.
     *
     * @since   2.0.0
     */
    private function contributions(): array
    {
        return [
            'version' => 4,
            'capabilities' => [[
                'id' => 'acme.shop.compose',
                'label' => 'Compose',
                'description' => 'Compose canonical blocks.',
            ]],
            'composition' => [
                'documents' => [[
                    'kind' => 'block-definition',
                    'canonical' => CanonicalJson::stringify($this->blockDocument()),
                ]],
                'host_bindings' => [[
                    'kind' => 'block-definition',
                    'id' => 'acme.shop/grid',
                    'renderer' => 'acme.shop.renderer.grid',
                    'capability' => 'acme.shop.compose',
                ]],
            ],
        ];
    }

    /**
     * A canonical document refuses each malformed shape at the arm that names it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACanonicalDocumentRefusesEachMalformedShape(): void
    {
        $refusals = [
            'over budget' => [
                '{"type":"' . str_repeat('a', 262200) . '"}',
                'cannot exceed 262144 bytes',
            ],
            'invalid JSON' => ['{broken', 'must be valid JSON'],
            'non-object' => ['["type"]', 'must be a JSON object'],
            'non-canonical bytes' => [
                "{\"type\": \"acme.shop/grid\"}",
                'exact canonical serialization',
            ],
            'missing identity' => ['{"kind":"grid"}', 'must carry its "type" identity'],
        ];
        foreach ($refusals as $label => [$canonical, $message]) {
            $refused = null;
            try {
                new CanonicalCompositionDocument(CanonicalCompositionKind::BlockDefinition, $canonical);
            } catch (InvalidArgumentException $exception) {
                $refused = $exception;
            }
            self::assertNotNull($refused, sprintf('The %s document was accepted.', $label));
            self::assertStringContainsString($message, $refused->getMessage());
        }

        $document = new CanonicalCompositionDocument(
            CanonicalCompositionKind::Inspector,
            '{"id":"acme.shop/price"}',
        );
        self::assertSame('acme.shop/price', $document->identity());
        self::assertSame('inspector acme.shop/price', $document->identifier());
        self::assertSame(
            ['kind' => 'inspector', 'id' => 'acme.shop/price', 'canonical' => '{"id":"acme.shop/price"}'],
            $document->toArray(),
        );
    }

    /**
     * A host binding refuses empty members and exports every declared member.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHostBindingRefusesEmptyMembersAndExportsItsDeclaration(): void
    {
        $refusals = [
            'empty document' => [['', null, null], 'must name its document'],
            'empty renderer' => [['acme.shop/grid', '', null], 'renderer cannot be empty'],
            'empty capability' => [['acme.shop/grid', null, ''], 'capability cannot be empty'],
        ];
        foreach ($refusals as $label => [[$documentId, $renderer, $capability], $message]) {
            $refused = null;
            try {
                new CompositionHostBinding(
                    CanonicalCompositionKind::BlockDefinition,
                    $documentId,
                    $renderer,
                    $capability,
                );
            } catch (InvalidArgumentException $exception) {
                $refused = $exception;
            }
            self::assertNotNull($refused, sprintf('The %s binding was accepted.', $label));
            self::assertStringContainsString($message, $refused->getMessage());
        }

        $binding = new CompositionHostBinding(
            CanonicalCompositionKind::Inspector,
            'acme.shop/price',
            null,
            'acme.shop.compose',
        );
        self::assertSame('inspector acme.shop/price', $binding->identifier());
        self::assertSame(
            [
                'kind' => 'inspector',
                'id' => 'acme.shop/price',
                'renderer' => null,
                'capability' => 'acme.shop.compose',
            ],
            $binding->toArray(),
        );
    }

    /**
     * Direct construction refuses each canonical separation breach the parser cannot reach.
     *
     * The manifest parser refuses schema and SPI disagreement before the set is built, so these
     * arms guard programmatic construction: the SPI fence in both directions, a binding without its
     * document or capability, a repeated declaration, and a non-string canonical payload.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDirectConstructionRefusesEachCanonicalSeparationBreach(): void
    {
        $owner = ContributionOwner::extension('acme/shop');
        $document = new CanonicalCompositionDocument(
            CanonicalCompositionKind::Inspector,
            '{"id":"acme.shop/price","owner":{"id":"acme.shop/inspectors","version":"1.0.0"}}',
        );
        $missingOwner = new CanonicalCompositionDocument(
            CanonicalCompositionKind::Inspector,
            '{"id":"acme.shop/missing-owner"}',
        );
        $scalarOwner = new CanonicalCompositionDocument(
            CanonicalCompositionKind::Inspector,
            '{"id":"acme.shop/scalar-owner","owner":"acme.shop/inspectors"}',
        );

        $refusals = [
            'canonical documents on SPI 3' => [
                static fn (): ManifestContributionSet => new ManifestContributionSet(
                    $owner,
                    spiVersion: ManifestContributionSet::COMPOSITION_SPI_VERSION,
                    canonicalDocuments: [$document],
                ),
                'require contribution SPI 4',
            ],
            'paraphrase vocabulary on SPI 4' => [
                static fn (): ManifestContributionSet => new ManifestContributionSet(
                    $owner,
                    spiVersion: ManifestContributionSet::CANONICAL_COMPOSITION_SPI_VERSION,
                    compositionControls: [new CompositionFieldControlDeclaration(
                        'acme.shop.money-control',
                        CompositionPropertyType::String,
                    )],
                ),
                'the schema-5 vocabulary is frozen at SPI 3',
            ],
            'binding without its document' => [
                static fn (): ManifestContributionSet => new ManifestContributionSet(
                    $owner,
                    spiVersion: ManifestContributionSet::CANONICAL_COMPOSITION_SPI_VERSION,
                    compositionHostBindings: [new CompositionHostBinding(
                        CanonicalCompositionKind::Inspector,
                        'acme.shop/price',
                    )],
                ),
                'must name a canonical document this manifest declares',
            ],
            'binding with an undeclared capability' => [
                static fn (): ManifestContributionSet => new ManifestContributionSet(
                    $owner,
                    spiVersion: ManifestContributionSet::CANONICAL_COMPOSITION_SPI_VERSION,
                    canonicalDocuments: [$document],
                    compositionHostBindings: [new CompositionHostBinding(
                        CanonicalCompositionKind::Inspector,
                        'acme.shop/price',
                        null,
                        'acme.shop.compose',
                    )],
                ),
                'capability must be one this manifest declares',
            ],
            'repeated declaration' => [
                static fn (): ManifestContributionSet => new ManifestContributionSet(
                    $owner,
                    spiVersion: ManifestContributionSet::CANONICAL_COMPOSITION_SPI_VERSION,
                    canonicalDocuments: [$document, $document],
                ),
                'declared more than once',
            ],
            'missing embedded owner' => [
                static fn (): ManifestContributionSet => new ManifestContributionSet(
                    $owner,
                    spiVersion: ManifestContributionSet::CANONICAL_COMPOSITION_SPI_VERSION,
                    canonicalDocuments: [$missingOwner],
                ),
                'document owner (missing) must belong to signed contribution owner',
            ],
            'scalar embedded owner' => [
                static fn (): ManifestContributionSet => new ManifestContributionSet(
                    $owner,
                    spiVersion: ManifestContributionSet::CANONICAL_COMPOSITION_SPI_VERSION,
                    canonicalDocuments: [$scalarOwner],
                ),
                'document owner (missing) must belong to signed contribution owner',
            ],
        ];
        foreach ($refusals as $label => [$construct, $message]) {
            $refused = null;
            try {
                $construct();
            } catch (InvalidArgumentException $exception) {
                $refused = $exception;
            }
            self::assertNotNull($refused, sprintf('The %s set was accepted.', $label));
            self::assertStringContainsString($message, $refused->getMessage());
        }

        $contributions = $this->contributions();
        $contributions['composition']['documents'][0]['canonical'] = 42;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry its canonical JSON string');
        ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/shop'),
            $contributions,
            6,
        );
    }

    /**
     * Studio ownership follows the slash namespace grammar for core and extension owners alike.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStudioOwnershipFollowsTheSlashNamespaceGrammar(): void
    {
        $extension = ContributionOwner::extension('acme/shop');
        $extension->assertOwns('block-definition acme.shop/grid', 'canonical composition document');
        $extension->assertOwns('acme.shop/grid', 'canonical_composition_document');
        ContributionOwner::core()->assertOwns('inspector core/price', 'composition_host_binding');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot claim canonical composition document identifier');
        $extension->assertOwns('block-definition evil.corp/grid', 'canonical composition document');
    }

    /**
     * A live registrar accepts declared canonical documents into the runtime document surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALiveRegistrarAcceptsDeclaredCanonicalDocuments(): void
    {
        $declarations = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/shop'),
            $this->contributions(),
            6,
        );
        $registries = new ExtensionContributionRegistrySet();
        $registrar = $registries->registrar($declarations->owner, $declarations);
        foreach ($declarations->capabilities() as $capability) {
            $registrar->capability($capability);
        }
        foreach ($declarations->canonicalCompositionDocuments() as $document) {
            $registrar->canonicalCompositionDocument($document);
        }
        $registrar->complete();

        $inventory = $registries->inventory($declarations->owner);
        $documents = $inventory['composition']['documents'] ?? null;
        self::assertIsArray($documents);
        self::assertCount(1, $documents);
        self::assertIsArray($documents[0]);
        self::assertSame('block-definition', $documents[0]['kind']);
        self::assertSame('acme.shop/grid', $documents[0]['id']);
        self::assertSame(
            $declarations->canonicalCompositionDocuments()[0]->canonical,
            $documents[0]['canonical'],
        );
        $bindings = $inventory['composition']['host_bindings'] ?? null;
        self::assertIsArray($bindings);
        self::assertSame([$declarations->compositionHostBindings()[0]->toArray()], $bindings);

        $registries->remove($declarations->owner);

        self::assertSame([], $registries->compositionHostBindings()->ownedBy($declarations->owner));
        self::assertSame([], $registries->inventory($declarations->owner)['composition']['host_bindings']);
    }

    /**
     * The corpus grid exemplar re-owned into the test extension's Studio namespace.
     *
     * @return  stdClass  A schema-valid block-definition document.
     *
     * @since   2.0.0
     */
    private function blockDocument(): stdClass
    {
        $document = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 3) . '/Fixtures/Studio/testkit/fixtures/block.grid.example.json',
            ),
            false,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $document);
        $document->type = 'acme.shop/grid';
        $document->owner = (object) ['id' => 'acme.shop/blocks', 'version' => '1.0.0'];

        return $document;
    }

    /**
     * Replace the first canonical document of a contributions section.
     *
     * @param   array<string, mixed>  $contributions  Section to modify.
     * @param   string                $canonical      Replacement canonical bytes.
     *
     * @return  array<string, mixed>  The modified section.
     *
     * @since   2.0.0
     */
    private static function withDocument(array $contributions, string $canonical): array
    {
        $contributions['composition']['documents'][0]['canonical'] = $canonical;

        return $contributions;
    }
}
