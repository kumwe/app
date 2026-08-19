<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\CompositionBlockDeclaration;
use Kumwe\App\Extension\Contribution\CompositionContributionRegistrar;
use Kumwe\App\Extension\Contribution\CompositionDesignVocabularyDeclaration;
use Kumwe\App\Extension\Contribution\CompositionFieldControlDeclaration;
use Kumwe\App\Extension\Contribution\CompositionInspectorDeclaration;
use Kumwe\App\Extension\Contribution\CompositionMigrationDeclaration;
use Kumwe\App\Extension\Contribution\CompositionPatternDeclaration;
use Kumwe\App\Extension\Contribution\CompositionPropertySchema;
use Kumwe\App\Extension\Contribution\CompositionPropertyType;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Contribution\OwnedExtensionContributionRegistrar;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Extension\Domain\ExtensionManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositionBlockDeclaration::class)]
#[CoversClass(CompositionDesignVocabularyDeclaration::class)]
#[CoversClass(CompositionFieldControlDeclaration::class)]
#[CoversClass(CompositionInspectorDeclaration::class)]
#[CoversClass(CompositionMigrationDeclaration::class)]
#[CoversClass(CompositionPatternDeclaration::class)]
#[CoversClass(CompositionPropertySchema::class)]
#[CoversClass(ManifestContributionSet::class)]
#[CoversClass(OwnedExtensionContributionRegistrar::class)]
#[CoversClass(ExtensionContributionRegistrySet::class)]
#[CoversClass(ExtensionManifest::class)]
/**
 * Pins the composition contribution contract as decision D16 froze it at Gate A.
 *
 * An extension declares blocks with bounded property schemas, patterns, field controls, inspectors,
 * design vocabulary including size roles, and composition migrations, in its signed manifest, through
 * the same registrar path as every other contribution. These are the assertions that prove the
 * declarations are validated at admission and at install — a malformed or unbounded declaration is
 * refused before any runtime exists to consume it — and that a package can neither widen nor swap its
 * declared surface after admission. Nothing here renders or stores; that is the point.
 *
 * @since  2.0.0
 */
final class CompositionContributionTest extends TestCase
{
    /**
     * Prove a package declares its whole composition surface through the contribution registrar.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionDeclaresItsCompositionSurfaceThroughTheContributionRegistrar(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/brochures');
        $block = $this->block();
        $pattern = new CompositionPatternDeclaration(
            'acme.brochures.stack',
            ['acme.brochures.hero', 'acme.brochures.hero'],
        );
        $control = new CompositionFieldControlDeclaration(
            'acme.brochures.emphasis-picker',
            CompositionPropertyType::Choice,
        );
        $inspector = new CompositionInspectorDeclaration('acme.brochures.hero-panel', 'acme.brochures.hero');
        $vocabulary = new CompositionDesignVocabularyDeclaration(
            'acme.brochures.vocabulary',
            ['accent', 'surface'],
            ['hero-card'],
            ['gutter', 'measure'],
        );
        $migration = $this->migration();
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            compositionBlocks: [$block],
            compositionPatterns: [$pattern],
            compositionControls: [$control],
            compositionInspectors: [$inspector],
            compositionVocabularies: [$vocabulary],
            compositionMigrations: [$migration],
        ));
        self::assertInstanceOf(CompositionContributionRegistrar::class, $registrar);

        $registrar->compositionBlock($block);
        $registrar->compositionPattern($pattern);
        $registrar->compositionFieldControl($control);
        $registrar->compositionInspector($inspector);
        $registrar->compositionDesignVocabulary($vocabulary);
        $registrar->compositionMigration($migration);
        $registrar->complete();

        $inventory = $registries->inventory($owner);
        self::assertIsArray($inventory['composition']);
        self::assertSame([$block->toArray()], $inventory['composition']['blocks']);
        self::assertSame([$pattern->toArray()], $inventory['composition']['patterns']);
        self::assertSame([$control->toArray()], $inventory['composition']['field_controls']);
        self::assertSame([$inspector->toArray()], $inventory['composition']['inspectors']);
        self::assertSame([$vocabulary->toArray()], $inventory['composition']['design_vocabularies']);
        self::assertSame([$migration->toArray()], $inventory['composition']['migrations']);
        self::assertSame(['gutter', 'measure'], $vocabulary->sizeRoles);
        self::assertSame(2, $block->version());
        self::assertSame(1, $pattern->version());
        self::assertSame(1, $control->version());
        self::assertSame(1, $inspector->version());
        self::assertSame(1, $vocabulary->version());
        self::assertSame(1, $migration->fromVersion());
        self::assertSame(2, $migration->toVersion());
    }

    /**
     * Prove core ships no composition contribution, so every surface is empty until a package declares one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreContributesNoCompositionSurface(): void
    {
        $registries = new ExtensionContributionRegistrySet();

        self::assertSame([], $registries->compositionBlocks()->definitions());
        self::assertSame([], $registries->compositionPatterns()->definitions());
        self::assertSame([], $registries->compositionFieldControls()->definitions());
        self::assertSame([], $registries->compositionInspectors()->definitions());
        self::assertSame([], $registries->compositionDesignVocabularies()->definitions());
        self::assertSame([], $registries->compositionMigrations()->definitions());
    }

    /**
     * Prove a declared composition surface survives the manifest round trip a runtime publication needs.
     *
     * The second half of the case is the one that matters for existing packages: a manifest declaring no
     * composition exports no `composition` section at all, so its bytes are the bytes it was admitted
     * against before the composition surfaces existed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeclaredCompositionSurfaceRoundTripsThroughTheManifest(): void
    {
        $owner = ContributionOwner::extension('acme/brochures');
        $declared = new ManifestContributionSet(
            $owner,
            spiVersion: ManifestContributionSet::COMPOSITION_SPI_VERSION,
            compositionBlocks: [$this->block()],
            compositionPatterns: [
                new CompositionPatternDeclaration('acme.brochures.stack', ['acme.brochures.hero']),
            ],
            compositionControls: [
                new CompositionFieldControlDeclaration(
                    'acme.brochures.emphasis-picker',
                    CompositionPropertyType::Choice,
                ),
            ],
            compositionInspectors: [
                new CompositionInspectorDeclaration('acme.brochures.hero-panel', 'acme.brochures.hero'),
            ],
            compositionVocabularies: [
                new CompositionDesignVocabularyDeclaration('acme.brochures.vocabulary', [], [], ['measure']),
            ],
            compositionMigrations: [$this->migration()],
        );

        $document = $declared->toArray();
        self::assertSame(3, $document['version']);
        self::assertIsArray($document['composition'] ?? null);
        $parsed = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/brochures'),
            $document,
            5,
        );
        self::assertSame($document, $parsed->toArray());
        self::assertSame('acme.brochures.hero', $parsed->compositionBlocks()[0]->identifier());
        self::assertSame('acme.brochures.stack', $parsed->compositionPatterns()[0]->identifier());
        self::assertSame('acme.brochures.emphasis-picker', $parsed->compositionFieldControls()[0]->identifier());
        self::assertSame('acme.brochures.hero-panel', $parsed->compositionInspectors()[0]->identifier());
        self::assertSame('acme.brochures.vocabulary', $parsed->compositionDesignVocabularies()[0]->identifier());
        self::assertSame('acme.brochures.hero-1-2', $parsed->compositionMigrations()[0]->identifier());

        $bare = new ManifestContributionSet($owner, spiVersion: ManifestContributionSet::COMPOSITION_SPI_VERSION);
        self::assertArrayNotHasKey('composition', $bare->toArray());
    }

    /**
     * Prove the composition section is a schema-5 grammar: earlier schemas refuse the key outright.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASchemaFourManifestRefusesTheCompositionSection(): void
    {
        $document = [
            'version' => ManifestContributionSet::CURRENT_SPI_VERSION,
            'composition' => ['blocks' => []],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contributions contains unknown key composition');
        ManifestContributionSet::fromManifest(ExtensionIdentifier::fromString('acme/brochures'), $document, 4);
    }

    /**
     * Prove a schema-5 manifest must declare contribution SPI 3, not an older revision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASchemaFiveManifestRequiresContributionSpiThree(): void
    {
        try {
            ManifestContributionSet::fromManifest(
                ExtensionIdentifier::fromString('acme/brochures'),
                ['version' => ManifestContributionSet::CURRENT_SPI_VERSION],
                5,
            );
            self::fail('A schema-5 manifest declaring SPI 2 was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('requires extension contribution SPI version 3', $exception->getMessage());
        }

        try {
            ManifestContributionSet::fromManifest(
                ExtensionIdentifier::fromString('acme/brochures'),
                ['version' => ManifestContributionSet::COMPOSITION_SPI_VERSION],
                6,
            );
            self::fail('An undeclared manifest schema was accepted for typed contributions.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('manifest schema 2, 3, 4, or 5', $exception->getMessage());
        }

        try {
            ExtensionManifest::fromJson('{"schema": 6}');
            self::fail('An undeclared manifest schema was accepted at the install boundary.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('schema must be 1, 2, 3, 4, or 5', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The extension contribution SPI version is unsupported.');
        new ManifestContributionSet(ContributionOwner::extension('acme/brochures'), spiVersion: 9);
    }

    /**
     * Prove every cross-reference a composition declaration makes must stay inside its own manifest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompositionReferencesOutsideTheDeclaredSurfaceAreRefused(): void
    {
        $owner = ContributionOwner::extension('acme/brochures');

        try {
            new ManifestContributionSet($owner, compositionBlocks: [
                new CompositionBlockDeclaration(
                    'acme.brochures.hero',
                    new CompositionPropertySchema([]),
                    [],
                    'zeta.shop.renderer',
                ),
            ]);
            self::fail('A renderer binding outside the owner namespace was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('composition renderer', $exception->getMessage());
        }

        try {
            new ManifestContributionSet($owner, compositionPatterns: [
                new CompositionPatternDeclaration('acme.brochures.stack', ['acme.brochures.ghost']),
            ]);
            self::fail('A pattern arranging an undeclared block was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must arrange blocks its own manifest declares', $exception->getMessage());
        }

        try {
            new ManifestContributionSet($owner, compositionInspectors: [
                new CompositionInspectorDeclaration('acme.brochures.hero-panel', 'acme.brochures.ghost'),
            ]);
            self::fail('An inspector for an undeclared block was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must open for a block', $exception->getMessage());
        }

        try {
            new ManifestContributionSet($owner, compositionMigrations: [$this->migration()]);
            self::fail('A migration for an undeclared block was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must step a block', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot target a revision its block has not reached');
        new ManifestContributionSet(
            $owner,
            compositionBlocks: [$this->block(version: 1)],
            compositionMigrations: [$this->migration()],
        );
    }

    /**
     * Prove a package cannot register a composition surface its signed manifest never declared.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPackageCannotWidenItsCompositionSurfaceAfterAdmission(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/brochures');
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            compositionBlocks: [$this->block()],
            compositionMigrations: [$this->migration()],
        ));

        try {
            $registrar->compositionBlock($this->block(slots: ['aside', 'body', 'footer']));
            self::fail('A package registered a slot set its manifest never declared.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('acme.brochures.hero', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $registrar->compositionPattern(
            new CompositionPatternDeclaration('zeta.shop.stack', ['zeta.shop.hero']),
        );
    }

    /**
     * Prove withdrawing the package withdraws its whole composition surface in the same sweep.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRemovingThePackageWithdrawsItsCompositionSurface(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/brochures');
        $block = $this->block();
        $migration = $this->migration();
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            compositionBlocks: [$block],
            compositionMigrations: [$migration],
        ));
        $registrar->compositionBlock($block);
        $registrar->compositionMigration($migration);
        $registrar->complete();
        self::assertCount(1, $registries->compositionBlocks()->definitions());

        $registries->remove($owner);

        self::assertSame([], $registries->compositionBlocks()->definitions());
        self::assertSame([], $registries->compositionMigrations()->definitions());
    }

    /**
     * Prove the signed schema-5 fixture manifest parses at install, and its unbounded twin is refused.
     *
     * `ExtensionManifest::fromJson` is the boundary both admission and install read a package through,
     * so this is the whole acceptance shape in one assertion pair: the compatibility manifest that
     * declares every composition kind is accepted, and the same bytes with one property's bound removed
     * are refused before any runtime exists to consume them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSignedFixtureManifestParsesAndItsUnboundedTwinIsRefused(): void
    {
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/generations/manifest-5/kumwe.json';
        $json = file_get_contents($path);
        self::assertIsString($json);

        $manifest = ExtensionManifest::fromJson($json);
        self::assertSame(5, $manifest->schemaVersion());
        $contributions = $manifest->contributions();
        self::assertSame(3, $contributions->spiVersion());
        self::assertCount(1, $contributions->compositionBlocks());
        self::assertCount(1, $contributions->compositionPatterns());
        self::assertCount(1, $contributions->compositionFieldControls());
        self::assertCount(1, $contributions->compositionInspectors());
        self::assertCount(1, $contributions->compositionDesignVocabularies());
        self::assertCount(1, $contributions->compositionMigrations());

        $unbounded = str_replace('"maximum_length": 120', '"maximum_length": 0', $json);
        self::assertNotSame($json, $unbounded);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must bound its length');
        ExtensionManifest::fromJson($unbounded);
    }

    /**
     * Prove the property schema refuses every unbounded or malformed shape the profile closes off.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPropertySchemaRefusesUnboundedAndMalformedDeclarations(): void
    {
        $failures = [
            'too many properties' => array_fill_keys(
                array_map(static fn (int $index): string => 'property_' . $index, range(1, 33)),
                ['type' => 'boolean', 'required' => false],
            ),
            'malformed name' => ['Bad-Name' => ['type' => 'boolean', 'required' => false]],
            'overlong name' => [
                str_repeat('a', 65) => ['type' => 'boolean', 'required' => false],
            ],
            'unknown type' => ['title' => ['type' => 'markup', 'required' => true]],
            'required not boolean' => ['title' => ['type' => 'boolean', 'required' => 'yes']],
            'missing bound member' => ['title' => ['type' => 'string', 'required' => true]],
            'foreign member' => [
                'title' => ['type' => 'boolean', 'required' => true, 'maximum_length' => 5],
            ],
            'zero length' => [
                'title' => ['type' => 'string', 'required' => true, 'maximum_length' => 0],
            ],
            'length past profile ceiling' => [
                'body' => ['type' => 'text', 'required' => false, 'maximum_length' => 10001],
            ],
            'inverted range' => [
                'columns' => ['type' => 'integer', 'required' => false, 'maximum' => 1, 'minimum' => 4],
            ],
            'non-integer range' => [
                'columns' => ['type' => 'number', 'required' => false, 'maximum' => 'four', 'minimum' => 1],
            ],
            'empty choice list' => ['emphasis' => ['type' => 'choice', 'required' => true, 'values' => []]],
            'overlong choice list' => [
                'emphasis' => [
                    'type' => 'choice',
                    'required' => true,
                    'values' => array_map(static fn (int $index): string => 'value-' . $index, range(1, 33)),
                ],
            ],
            'non-string choice value' => [
                'emphasis' => ['type' => 'choice', 'required' => true, 'values' => ['muted', 7]],
            ],
            'repeated choice value' => [
                'emphasis' => ['type' => 'choice', 'required' => true, 'values' => ['muted', 'muted']],
            ],
            'unknown reference kind' => [
                'illustration' => ['type' => 'reference', 'required' => false, 'kind' => 'script'],
            ],
        ];

        foreach ($failures as $label => $properties) {
            try {
                new CompositionPropertySchema($properties);
                self::fail(sprintf('An unbounded or malformed property schema was accepted: %s.', $label));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('specification must be an object');
        CompositionPropertySchema::fromArray(['title' => ['string']]);
    }

    /**
     * Prove the property schema canonicalizes every published type into one deterministic export.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPropertySchemaCanonicalizesEveryPublishedType(): void
    {
        $schema = new CompositionPropertySchema([
            'show_icon' => ['type' => 'boolean', 'required' => false],
            'emphasis' => ['values' => ['strong', 'muted'], 'type' => 'choice', 'required' => true],
            'body' => ['type' => 'text', 'required' => false, 'maximum_length' => 4000],
            'columns' => ['minimum' => 1, 'type' => 'integer', 'required' => false, 'maximum' => 4],
            'ratio' => ['type' => 'number', 'required' => false, 'maximum' => 100, 'minimum' => 0],
            'illustration' => ['type' => 'reference', 'required' => false, 'kind' => 'media'],
            'heading' => ['type' => 'string', 'required' => true, 'maximum_length' => 120],
        ]);

        $document = $schema->toArray();
        self::assertSame(
            ['body', 'columns', 'emphasis', 'heading', 'illustration', 'ratio', 'show_icon'],
            array_keys($document),
        );
        self::assertSame(
            ['type' => 'choice', 'required' => true, 'values' => ['muted', 'strong']],
            $document['emphasis'],
        );
        self::assertSame(
            ['type' => 'integer', 'required' => false, 'maximum' => 4, 'minimum' => 1],
            $document['columns'],
        );
        self::assertSame($document, CompositionPropertySchema::fromArray($document)->toArray());
    }

    /**
     * Prove a block declaration is refused wherever it is malformed, and canonicalized where it is not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABlockDeclarationIsValidatedAndCanonicalized(): void
    {
        $schema = new CompositionPropertySchema([]);
        $constructions = [
            'unnamespaced identifier' => static fn (): CompositionBlockDeclaration
                => new CompositionBlockDeclaration('hero', $schema, [], 'acme.brochures.renderer'),
            'unnamespaced renderer' => static fn (): CompositionBlockDeclaration
                => new CompositionBlockDeclaration('acme.brochures.hero', $schema, [], 'renderer'),
            'too many slots' => static fn (): CompositionBlockDeclaration => new CompositionBlockDeclaration(
                'acme.brochures.hero',
                $schema,
                array_map(static fn (int $index): string => 'slot_' . $index, range(1, 17)),
                'acme.brochures.renderer',
            ),
            'malformed slot name' => static fn (): CompositionBlockDeclaration
                => new CompositionBlockDeclaration('acme.brochures.hero', $schema, ['Body'], 'acme.brochures.renderer'),
            'repeated slot name' => static fn (): CompositionBlockDeclaration => new CompositionBlockDeclaration(
                'acme.brochures.hero',
                $schema,
                ['body', 'body'],
                'acme.brochures.renderer',
            ),
            'non-positive version' => static fn (): CompositionBlockDeclaration => new CompositionBlockDeclaration(
                'acme.brochures.hero',
                $schema,
                [],
                'acme.brochures.renderer',
                0,
            ),
        ];
        foreach ($constructions as $label => $construction) {
            try {
                $construction();
                self::fail(sprintf('A malformed block declaration was accepted: %s.', $label));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $block = $this->block(slots: ['body', 'aside']);
        self::assertSame(['aside', 'body'], $block->slots);
        self::assertSame('acme.brochures.hero-renderer', $block->renderer());
        self::assertSame($block->toArray(), CompositionBlockDeclaration::fromArray($block->toArray())->toArray());

        try {
            CompositionBlockDeclaration::fromArray(['block_id' => 'acme.brochures.hero']);
            self::fail('A block declaration missing its members was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }
        try {
            CompositionBlockDeclaration::fromArray([...$block->toArray(), 'slots' => 'body']);
            self::fail('A block declaration with mistyped slots was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('member has the wrong type', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('slot must be a string');
        CompositionBlockDeclaration::fromArray([...$block->toArray(), 'slots' => [7]]);
    }

    /**
     * Prove a pattern declaration is bounded, ordered, and closed over its members.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPatternDeclarationIsValidatedAndKeepsItsArrangementOrder(): void
    {
        $constructions = [
            'unnamespaced identifier' => static fn (): CompositionPatternDeclaration
                => new CompositionPatternDeclaration('stack', ['acme.brochures.hero']),
            'empty arrangement' => static fn (): CompositionPatternDeclaration
                => new CompositionPatternDeclaration('acme.brochures.stack', []),
            'overlong arrangement' => static fn (): CompositionPatternDeclaration
                => new CompositionPatternDeclaration(
                    'acme.brochures.stack',
                    array_fill(0, 33, 'acme.brochures.hero'),
                ),
            'unnamespaced reference' => static fn (): CompositionPatternDeclaration
                => new CompositionPatternDeclaration('acme.brochures.stack', ['hero']),
            'non-positive version' => static fn (): CompositionPatternDeclaration
                => new CompositionPatternDeclaration('acme.brochures.stack', ['acme.brochures.hero'], 0),
        ];
        foreach ($constructions as $label => $construction) {
            try {
                $construction();
                self::fail(sprintf('A malformed pattern declaration was accepted: %s.', $label));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $pattern = new CompositionPatternDeclaration(
            'acme.brochures.stack',
            ['acme.brochures.hero', 'acme.brochures.aside', 'acme.brochures.hero'],
        );
        self::assertSame(
            ['acme.brochures.hero', 'acme.brochures.aside', 'acme.brochures.hero'],
            $pattern->blocks,
        );
        self::assertSame($pattern->toArray(), CompositionPatternDeclaration::fromArray($pattern->toArray())->toArray());

        try {
            CompositionPatternDeclaration::fromArray(['pattern_id' => 'acme.brochures.stack']);
            self::fail('A pattern declaration missing its members was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }
        try {
            CompositionPatternDeclaration::fromArray([...$pattern->toArray(), 'blocks' => 'hero']);
            self::fail('A pattern declaration with mistyped blocks was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('member has the wrong type', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('block reference must be a string');
        CompositionPatternDeclaration::fromArray([...$pattern->toArray(), 'blocks' => [7]]);
    }

    /**
     * Prove a field control declaration binds to the published vocabulary and nothing else.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFieldControlDeclarationBindsToThePublishedVocabulary(): void
    {
        try {
            new CompositionFieldControlDeclaration('picker', CompositionPropertyType::Choice);
            self::fail('An unnamespaced field control identifier was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must be namespaced', $exception->getMessage());
        }
        try {
            new CompositionFieldControlDeclaration('acme.brochures.picker', CompositionPropertyType::Choice, 0);
            self::fail('A non-positive field control version was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('positive integer', $exception->getMessage());
        }

        $control = new CompositionFieldControlDeclaration('acme.brochures.picker', CompositionPropertyType::Reference);
        self::assertSame('reference', $control->toArray()['edits']);
        self::assertSame(
            $control->toArray(),
            CompositionFieldControlDeclaration::fromArray($control->toArray())->toArray(),
        );

        try {
            CompositionFieldControlDeclaration::fromArray(['control_id' => 'acme.brochures.picker']);
            self::fail('A field control declaration missing its members was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }
        try {
            CompositionFieldControlDeclaration::fromArray([...$control->toArray(), 'version' => 'one']);
            self::fail('A field control declaration with a mistyped version was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('member has the wrong type', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must edit a published property type');
        CompositionFieldControlDeclaration::fromArray([...$control->toArray(), 'edits' => 'markup']);
    }

    /**
     * Prove an inspector declaration is namespaced on both ends and closed over its members.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInspectorDeclarationIsValidatedOnBothEnds(): void
    {
        try {
            new CompositionInspectorDeclaration('panel', 'acme.brochures.hero');
            self::fail('An unnamespaced inspector identifier was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('identifier must be namespaced', $exception->getMessage());
        }
        try {
            new CompositionInspectorDeclaration('acme.brochures.hero-panel', 'hero');
            self::fail('An unnamespaced inspector block reference was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('block reference', $exception->getMessage());
        }
        try {
            new CompositionInspectorDeclaration('acme.brochures.hero-panel', 'acme.brochures.hero', 0);
            self::fail('A non-positive inspector version was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('positive integer', $exception->getMessage());
        }

        $inspector = new CompositionInspectorDeclaration('acme.brochures.hero-panel', 'acme.brochures.hero');
        self::assertSame('acme.brochures.hero', $inspector->block());
        self::assertSame(
            $inspector->toArray(),
            CompositionInspectorDeclaration::fromArray($inspector->toArray())->toArray(),
        );

        try {
            CompositionInspectorDeclaration::fromArray(['inspector_id' => 'acme.brochures.hero-panel']);
            self::fail('An inspector declaration missing its members was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('member has the wrong type');
        CompositionInspectorDeclaration::fromArray([...$inspector->toArray(), 'block' => 7]);
    }

    /**
     * Prove a design vocabulary is bounded, sorted, and must include at least one remappable name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADesignVocabularyIsBoundedAndSorted(): void
    {
        $constructions = [
            'unnamespaced identifier' => static fn (): CompositionDesignVocabularyDeclaration
                => new CompositionDesignVocabularyDeclaration('vocabulary', ['accent'], [], []),
            'empty vocabulary' => static fn (): CompositionDesignVocabularyDeclaration
                => new CompositionDesignVocabularyDeclaration('acme.brochures.vocabulary', [], [], []),
            'overlong token list' => static fn (): CompositionDesignVocabularyDeclaration
                => new CompositionDesignVocabularyDeclaration(
                    'acme.brochures.vocabulary',
                    array_map(static fn (int $index): string => 'token-' . $index, range(1, 65)),
                    [],
                    [],
                ),
            'malformed size role' => static fn (): CompositionDesignVocabularyDeclaration
                => new CompositionDesignVocabularyDeclaration('acme.brochures.vocabulary', [], [], ['Wide']),
            'repeated recipe' => static fn (): CompositionDesignVocabularyDeclaration
                => new CompositionDesignVocabularyDeclaration(
                    'acme.brochures.vocabulary',
                    [],
                    ['card', 'card'],
                    [],
                ),
            'non-positive version' => static fn (): CompositionDesignVocabularyDeclaration
                => new CompositionDesignVocabularyDeclaration('acme.brochures.vocabulary', ['accent'], [], [], 0),
        ];
        foreach ($constructions as $label => $construction) {
            try {
                $construction();
                self::fail(sprintf('A malformed design vocabulary was accepted: %s.', $label));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $vocabulary = new CompositionDesignVocabularyDeclaration(
            'acme.brochures.vocabulary',
            ['surface', 'accent'],
            ['hero-card'],
            ['measure', 'gutter'],
        );
        self::assertSame(['accent', 'surface'], $vocabulary->tokens);
        self::assertSame(['gutter', 'measure'], $vocabulary->sizeRoles);
        self::assertSame(
            $vocabulary->toArray(),
            CompositionDesignVocabularyDeclaration::fromArray($vocabulary->toArray())->toArray(),
        );

        try {
            CompositionDesignVocabularyDeclaration::fromArray(['vocabulary_id' => 'acme.brochures.vocabulary']);
            self::fail('A design vocabulary declaration missing its members was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }
        try {
            CompositionDesignVocabularyDeclaration::fromArray([...$vocabulary->toArray(), 'version' => 'one']);
            self::fail('A design vocabulary declaration with a mistyped version was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('member has the wrong type', $exception->getMessage());
        }
        try {
            CompositionDesignVocabularyDeclaration::fromArray([...$vocabulary->toArray(), 'tokens' => 'accent']);
            self::fail('A design vocabulary declaration with mistyped tokens was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('member has the wrong type', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name must be a string');
        CompositionDesignVocabularyDeclaration::fromArray([...$vocabulary->toArray(), 'size_roles' => [7]]);
    }

    /**
     * Prove a migration declaration keeps its closed action vocabulary and ascending revisions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMigrationDeclarationKeepsItsClosedVocabulary(): void
    {
        $rename = ['action' => 'rename', 'property' => 'title', 'to' => 'heading'];
        $constructions = [
            'unnamespaced identifier' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration('step', 'acme.brochures.hero', 1, 2, [$rename]),
            'unnamespaced block' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration('acme.brochures.step', 'hero', 1, 2, [$rename]),
            'non-positive source revision' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration('acme.brochures.step', 'acme.brochures.hero', 0, 2, [$rename]),
            'descending revisions' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration('acme.brochures.step', 'acme.brochures.hero', 2, 2, [$rename]),
            'no operations' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration('acme.brochures.step', 'acme.brochures.hero', 1, 2, []),
            'too many operations' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration(
                    'acme.brochures.step',
                    'acme.brochures.hero',
                    1,
                    2,
                    array_fill(0, 33, $rename),
                ),
            'unknown action' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration('acme.brochures.step', 'acme.brochures.hero', 1, 2, [
                    ['action' => 'transform', 'property' => 'title'],
                ]),
            'foreign operation member' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration('acme.brochures.step', 'acme.brochures.hero', 1, 2, [
                    ['action' => 'remove', 'property' => 'title', 'to' => 'heading'],
                ]),
            'malformed property' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration('acme.brochures.step', 'acme.brochures.hero', 1, 2, [
                    ['action' => 'clear', 'property' => 'Title'],
                ]),
            'rename to the same name' => static fn (): CompositionMigrationDeclaration
                => new CompositionMigrationDeclaration('acme.brochures.step', 'acme.brochures.hero', 1, 2, [
                    ['action' => 'rename', 'property' => 'title', 'to' => 'title'],
                ]),
        ];
        foreach ($constructions as $label => $construction) {
            try {
                $construction();
                self::fail(sprintf('A malformed migration declaration was accepted: %s.', $label));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $migration = $this->migration();
        self::assertSame('acme.brochures.hero', $migration->block());
        self::assertSame(
            $migration->toArray(),
            CompositionMigrationDeclaration::fromArray($migration->toArray())->toArray(),
        );

        try {
            CompositionMigrationDeclaration::fromArray(['migration_id' => 'acme.brochures.step']);
            self::fail('A migration declaration missing its members was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }
        try {
            CompositionMigrationDeclaration::fromArray([...$migration->toArray(), 'from_version' => 'one']);
            self::fail('A migration declaration with a mistyped revision was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('member has the wrong type', $exception->getMessage());
        }
        try {
            CompositionMigrationDeclaration::fromArray([...$migration->toArray(), 'operations' => [['rename']]]);
            self::fail('A migration operation that is not an object was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('operation must be an object', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('operation member must be a string');
        CompositionMigrationDeclaration::fromArray([
            ...$migration->toArray(),
            'operations' => [['action' => 'clear', 'property' => 7]],
        ]);
    }

    /**
     * Build the hero block declaration most cases in this suite share.
     *
     * @param   list<string>  $slots    Declared slot names.
     * @param   int           $version  Declared block revision.
     *
     * @return  CompositionBlockDeclaration  Owner-namespaced block with one bounded property.
     *
     * @since   2.0.0
     */
    private function block(array $slots = ['aside', 'body'], int $version = 2): CompositionBlockDeclaration
    {
        return new CompositionBlockDeclaration(
            'acme.brochures.hero',
            new CompositionPropertySchema([
                'heading' => ['type' => 'string', 'required' => true, 'maximum_length' => 120],
            ]),
            $slots,
            'acme.brochures.hero-renderer',
            $version,
        );
    }

    /**
     * Build the migration declaration most cases in this suite share.
     *
     * @return  CompositionMigrationDeclaration  Migration stepping the hero block from one to two.
     *
     * @since   2.0.0
     */
    private function migration(): CompositionMigrationDeclaration
    {
        return new CompositionMigrationDeclaration(
            'acme.brochures.hero-1-2',
            'acme.brochures.hero',
            1,
            2,
            [
                ['action' => 'rename', 'property' => 'title', 'to' => 'heading'],
                ['action' => 'remove', 'property' => 'legacy_style'],
                ['action' => 'clear', 'property' => 'heading'],
            ],
        );
    }
}
