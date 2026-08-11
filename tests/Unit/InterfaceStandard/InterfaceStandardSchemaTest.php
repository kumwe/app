<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\InterfaceStandard;

use BackedEnum;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\ResponsivePriority;
use Kumwe\CMS\InterfaceStandard\SurfaceActor;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceDefinition;
use Kumwe\CMS\InterfaceStandard\SurfaceIntent;
use Kumwe\CMS\InterfaceStandard\SurfacePattern;
use Kumwe\CMS\InterfaceStandard\SurfaceState;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
/**
 * Keeps the portable KIS schemas and examples aligned with the admitted PHP vocabulary.
 *
 * @since  2.0.0
 */
final class InterfaceStandardSchemaTest extends TestCase
{
    /**
     * The surface schema exposes exactly the runtime enums and its canonical example is admissible.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSurfaceSchemaAndExampleMirrorTheTypedContract(): void
    {
        $schema = $this->document('docs/interface-standard/schemas/surface-declaration.schema.json');
        $example = $this->document('docs/interface-standard/examples/extension-surface.json');
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
        self::assertSame('urn:kumwe:kis:1.0:surface-declaration', $schema['$id'] ?? null);
        self::assertSame(array_keys($example), $schema['required'] ?? null);
        self::assertSame(self::values(SurfaceArea::cases()), $properties['area']['enum'] ?? null);
        self::assertSame(self::values(SurfaceActor::cases()), $properties['actor']['enum'] ?? null);
        self::assertSame(self::values(SurfaceIntent::cases()), $properties['intent']['enum'] ?? null);
        self::assertSame(self::values(SurfacePattern::cases()), $properties['pattern']['enum'] ?? null);
        self::assertSame(self::values(SurfaceState::cases()), $properties['states']['items']['enum'] ?? null);
        self::assertSame(
            self::values(CustomizationSlot::cases()),
            $properties['customization']['items']['properties']['slot']['enum'] ?? null,
        );
        self::assertSame(
            self::values(CustomizationScope::cases()),
            $properties['customization']['items']['properties']['scope']['enum'] ?? null,
        );
        self::assertSame(
            self::values(ResponsivePriority::cases()),
            $properties['responsive']['items']['properties']['priority']['enum'] ?? null,
        );

        $definition = SurfaceDefinition::fromArray(
            ContributionOwner::extension('acme/inspections'),
            $example,
        );
        self::assertSame($example, $definition->toArray());
    }

    /**
     * The persisted-preference schema covers every allowlisted slot and scope exactly once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPreferenceSchemaAndExampleCoverTheCustomizationVocabulary(): void
    {
        $schema = $this->document('docs/interface-standard/schemas/presentation-preference.schema.json');
        $example = $this->document('docs/interface-standard/examples/presentation-preference.json');
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);

        self::assertSame('urn:kumwe:kis:1.0:presentation-preference', $schema['$id'] ?? null);
        self::assertSame(self::values(CustomizationSlot::cases()), $properties['slot']['enum'] ?? null);
        self::assertSame(self::values(CustomizationScope::cases()), $properties['scope']['enum'] ?? null);
        self::assertSame(array_keys($example), $schema['required'] ?? null);

        $branches = $schema['oneOf'] ?? null;
        self::assertIsArray($branches);
        $branchSlots = [];
        foreach ($branches as $branch) {
            self::assertIsArray($branch);
            $slot = $branch['properties']['slot']['const'] ?? null;
            self::assertIsString($slot);
            $branchSlots[] = $slot;
        }
        self::assertSame(self::values(CustomizationSlot::cases()), $branchSlots);
        self::assertContains($example['slot'] ?? null, $branchSlots);
        self::assertContains($example['scope'] ?? null, self::values(CustomizationScope::cases()));
    }

    /**
     * Reject collection sizes that the portable schema refuses, including direct runtime parsing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSurfaceRuntimeEnforcesPortableCollectionBudgets(): void
    {
        $example = $this->document('docs/interface-standard/examples/extension-surface.json');
        $example['capabilities'] = array_map(
            static fn (int $index): string => 'acme.capability-' . $index,
            range(1, 65),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('capabilities must contain between 0 and 64');

        SurfaceDefinition::fromArray(ContributionOwner::extension('acme/inspections'), $example);
    }

    /**
     * Require at least one semantic responsive priority at the runtime boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSurfaceRuntimeRejectsEmptyResponsiveContract(): void
    {
        $example = $this->document('docs/interface-standard/examples/extension-surface.json');
        $example['responsive'] = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('responsive must contain between 1 and 64');

        SurfaceDefinition::fromArray(ContributionOwner::extension('acme/inspections'), $example);
    }

    /**
     * Decode one repository-owned JSON document without accepting a list or invalid object.
     *
     * @param   string  $relativePath  Repository-relative schema or example path.
     *
     * @return  array<string, mixed>  Decoded keyed document.
     *
     * @since   2.0.0
     */
    private function document(string $relativePath): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($contents);
        $document = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertFalse(array_is_list($document));

        return $document;
    }

    /**
     * Export backed-enum values in their normative declaration order.
     *
     * @param   list<BackedEnum>  $cases  Enum cases to export.
     *
     * @return  list<int|string>  Stable backed values.
     *
     * @since   2.0.0
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (BackedEnum $case): int|string => $case->value, $cases);
    }
}
