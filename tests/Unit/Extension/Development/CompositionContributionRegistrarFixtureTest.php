<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Development;

use Kumwe\CMS\Extension\Contribution\CompositionBlockDeclaration;
use Kumwe\CMS\Extension\Contribution\CompositionContributionRegistrar;
use Kumwe\CMS\Extension\Contribution\CompositionDesignVocabularyDeclaration;
use Kumwe\CMS\Extension\Contribution\CompositionFieldControlDeclaration;
use Kumwe\CMS\Extension\Contribution\CompositionInspectorDeclaration;
use Kumwe\CMS\Extension\Contribution\CompositionMigrationDeclaration;
use Kumwe\CMS\Extension\Contribution\CompositionPatternDeclaration;
use Kumwe\CMS\Extension\Contribution\CompositionPropertySchema;
use Kumwe\CMS\Extension\Contribution\CompositionPropertyType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

#[CoversClass(CompositionBlockDeclaration::class)]
#[CoversClass(CompositionDesignVocabularyDeclaration::class)]
#[CoversClass(CompositionFieldControlDeclaration::class)]
#[CoversClass(CompositionInspectorDeclaration::class)]
#[CoversClass(CompositionMigrationDeclaration::class)]
#[CoversClass(CompositionPatternDeclaration::class)]
#[CoversClass(CompositionPropertySchema::class)]
/**
 * Pins the additive contracts a package contributing to composition compiles against.
 *
 * They are pinned in a fixture of their own rather than inside a frozen SPI baseline, for the same
 * reason the KIS, rate-provider, translation-group and unit-conversion contracts are: an addition must
 * not rewrite the bytes existing providers were admitted against. What is locked here is the whole of
 * the surface a composing package touches — the six-method registrar it contributes through, the
 * manifest section its declarations are read from, the members each declaration kind carries, the
 * closed property-type vocabulary, the closed migration action list, and the closed reference kinds.
 *
 * It attributes to the declaration classes rather than declaring that it covers nothing, because it
 * does not only read a fixture: it builds one real declaration of every kind and holds each exported
 * member list to the recorded names. The registrar interface is asserted structurally, which is a shape
 * assertion rather than a covered execution path.
 *
 * @since  2.0.0
 */
final class CompositionContributionRegistrarFixtureTest extends TestCase
{
    /**
     * Require the additive registrar to keep the exact signatures a published package compiles against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAdditiveCompositionRegistrarRemainsSourceCompatible(): void
    {
        $fixture = $this->fixture();
        $interfaces = $fixture['interfaces'] ?? null;
        self::assertIsArray($interfaces);

        foreach ($interfaces as $interface => $expected) {
            self::assertIsString($interface);
            self::assertIsArray($expected);
            self::assertTrue(interface_exists($interface), sprintf('Missing public interface %s.', $interface));
            $actual = [];
            foreach ((new ReflectionClass($interface))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $interface) {
                    $actual[] = $this->signature($method);
                }
            }
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);
            self::assertSame($expected, $actual, sprintf('Public interface %s changed.', $interface));
        }
        self::assertTrue(is_a(
            \Kumwe\CMS\Extension\Contribution\OwnedExtensionContributionRegistrar::class,
            CompositionContributionRegistrar::class,
            true,
        ));
    }

    /**
     * Require the published property-type vocabulary to keep exactly its recorded values.
     *
     * The vocabulary is what bounds every declared block property and every declared field control, so
     * removing or renaming a case would orphan admitted declarations while widening it is an additive
     * change that must arrive deliberately, through this fixture's bytes changing in review.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePublishedPropertyTypeVocabularyRemainsWireCompatible(): void
    {
        $fixture = $this->fixture();
        $enums = $fixture['enums'] ?? null;
        self::assertIsArray($enums);

        foreach ($enums as $enum => $expected) {
            self::assertIsString($enum);
            self::assertTrue(enum_exists($enum), sprintf('Missing public enum %s.', $enum));
            /** @var class-string<\BackedEnum> $enum */
            $actual = array_map(static fn (\BackedEnum $case): int|string => $case->value, $enum::cases());
            self::assertSame($expected, $actual, sprintf('Public enum %s changed.', $enum));
        }
        self::assertSame(CompositionMigrationDeclaration::ACTIONS, $fixture['migration_actions'] ?? null);
        self::assertSame(CompositionPropertySchema::REFERENCE_KINDS, $fixture['reference_kinds'] ?? null);
    }

    /**
     * Require every declaration kind to keep exactly the members it was admitted with.
     *
     * The manifest section and the member names are part of the contract because a signed manifest is
     * read before any of a package's code runs; renaming any of them would silently stop reconciling a
     * published package's composition surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeclaredManifestSectionAndMembersRemainWireCompatible(): void
    {
        $fixture = $this->fixture();

        self::assertSame('contributions.composition', $fixture['manifest_section'] ?? null);
        $members = $fixture['declaration_members'] ?? null;
        self::assertIsArray($members);
        self::assertSame(array_keys($members), $fixture['section_keys'] ?? null);

        $block = new CompositionBlockDeclaration(
            'acme.brochures.hero',
            new CompositionPropertySchema([
                'title' => ['type' => 'string', 'required' => true, 'maximum_length' => 120],
            ]),
            ['body'],
            'acme.brochures.hero-renderer',
            2,
        );
        $declarations = [
            'blocks' => $block,
            'patterns' => new CompositionPatternDeclaration('acme.brochures.stack', ['acme.brochures.hero']),
            'field_controls' => new CompositionFieldControlDeclaration(
                'acme.brochures.length-picker',
                CompositionPropertyType::Integer,
            ),
            'inspectors' => new CompositionInspectorDeclaration('acme.brochures.hero-panel', 'acme.brochures.hero'),
            'design_vocabularies' => new CompositionDesignVocabularyDeclaration(
                'acme.brochures.vocabulary',
                ['accent'],
                [],
                ['measure'],
            ),
            'migrations' => new CompositionMigrationDeclaration(
                'acme.brochures.hero-1-2',
                'acme.brochures.hero',
                1,
                2,
                [['action' => 'clear', 'property' => 'title']],
            ),
        ];
        foreach ($declarations as $section => $declaration) {
            self::assertSame(
                $members[$section] ?? null,
                array_keys($declaration->toArray()),
                sprintf('Declaration members changed for composition section %s.', $section),
            );
        }
    }

    /**
     * Load the immutable compatibility fixture, proving its bytes are the released ones.
     *
     * @return  array<string, mixed>  Compatibility fixture document.
     *
     * @since   2.0.0
     */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/composition-contribution-registrar-v1.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        self::assertSame(
            '30c0c68d7dd5ef53495ecef41bfc5fc512820031c1ceb30e793d55a7985a22d1',
            hash('sha256', $json),
        );
        $fixture = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('kumwe-composition-contribution-registrar-v1', $fixture['format'] ?? null);

        return $fixture;
    }

    /**
     * Render one declared interface method into the fixture's canonical signature grammar.
     *
     * @param   ReflectionMethod  $method  Declared public interface method.
     *
     * @return  string  Fully-qualified parameter and return signature.
     *
     * @since   2.0.0
     */
    private function signature(ReflectionMethod $method): string
    {
        $parameters = array_map(
            fn (ReflectionParameter $parameter): string => $this->parameter($parameter),
            $method->getParameters(),
        );
        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);

        return sprintf('%s(%s): %s', $method->getName(), implode(', ', $parameters), $returnType->getName());
    }

    /**
     * Render one method parameter as the fixture spells it.
     *
     * @param   ReflectionParameter  $parameter  Parameter to encode.
     *
     * @return  string  Canonical parameter fragment.
     *
     * @since   2.0.0
     */
    private function parameter(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName() . ' $' . $parameter->getName();
    }
}
