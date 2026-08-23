<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\CanonicalCompositionDocument;
use Kumwe\App\Extension\Contribution\CanonicalCompositionKind;
use Kumwe\App\Extension\Contribution\CanonicalCompositionRegistrar;
use Kumwe\App\Extension\Contribution\CompositionHostBinding;
use Kumwe\App\Extension\Contribution\CompositionPropertySchema;
use Kumwe\App\Extension\Contribution\Manifest5CompositionAdapter;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Contribution\OwnedExtensionContributionRegistrar;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\CanonicalJson;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\SchemaPropertyProfile;
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
#[CoversClass(Manifest5CompositionAdapter::class)]
#[CoversClass(ManifestContributionSet::class)]
final class CanonicalCompositionTest extends TestCase
{
    /**
     * A schema-6 section with a valid canonical block and bindings parses into the declared set.
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
