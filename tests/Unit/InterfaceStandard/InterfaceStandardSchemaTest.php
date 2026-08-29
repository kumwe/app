<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\InterfaceStandard;

use BackedEnum;
use InvalidArgumentException;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\ResponsivePriority;
use Kumwe\App\InterfaceStandard\SurfaceActor;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceDefinition;
use Kumwe\App\InterfaceStandard\SurfaceIntent;
use Kumwe\App\InterfaceStandard\SurfacePattern;
use Kumwe\App\InterfaceStandard\SurfaceState;
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
        self::assertSame(
            '^[a-z0-9][a-z0-9._-]*\\.[a-z0-9._-]*[a-z0-9]$',
            $schema['$defs']['surfaceIdentifier']['pattern'] ?? null,
        );
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
        self::assertSame('element', $properties['responsive']['x-kumwe-uniqueBy'] ?? null);

        $scopeRules = [];
        foreach ($properties['customization']['items']['allOf'] ?? [] as $rule) {
            self::assertIsArray($rule);
            $slot = $rule['if']['properties']['slot']['const'] ?? null;
            $scope = $rule['then']['properties']['scope'] ?? null;
            self::assertIsString($slot);
            self::assertIsArray($scope);
            $scopeRules[$slot] = isset($scope['enum']) ? $scope['enum'] : [$scope['const'] ?? null];
        }
        self::assertSame([
            'columns' => ['administrator', 'role-workspace', 'user'],
            'density' => ['site', 'administrator', 'role-workspace', 'user'],
            'saved-views' => ['administrator', 'role-workspace', 'user'],
            'layout' => ['site', 'administrator'],
            'theme-mode' => ['site', 'user'],
            'dashboard-cards' => ['administrator', 'role-workspace', 'user'],
            'landing-workspace' => ['administrator', 'role-workspace', 'user'],
            'navigation-shortcuts' => ['role-workspace', 'user'],
            'labels-help' => ['administrator'],
        ], $scopeRules);

        $areaActorRules = [];
        foreach ($schema['allOf'] ?? [] as $rule) {
            self::assertIsArray($rule);
            $area = $rule['if']['properties']['area']['const'] ?? null;
            if (!is_string($area)) {
                continue;
            }
            $actor = $rule['then']['properties']['actor'] ?? null;
            self::assertIsArray($actor);
            $areaActorRules[$area] = isset($actor['enum']) ? $actor['enum'] : [$actor['const'] ?? null];
        }
        self::assertSame([
            'administrator' => ['administrator', 'public'],
            'portal' => ['portal', 'public'],
            'public' => ['public'],
        ], $areaActorRules);

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
        self::assertSame(
            ['owner' => 'owner', 'surface' => 'surface'],
            $schema['x-kumwe-ownedSurface'] ?? null,
        );
        self::assertSame(self::values(CustomizationSlot::cases()), $properties['slot']['enum'] ?? null);
        self::assertSame(self::values(CustomizationScope::cases()), $properties['scope']['enum'] ?? null);
        self::assertSame(array_keys($example), $schema['required'] ?? null);
        self::assertSame(127, $properties['owner']['maxLength'] ?? null);
        self::assertSame(
            '^(?:core|[a-z0-9][a-z0-9._-]{0,62}/[a-z0-9][a-z0-9._-]{0,62})$',
            $properties['owner']['pattern'] ?? null,
        );
        self::assertSame(
            '^[a-z0-9][a-z0-9._-]*\\.[a-z0-9._-]*[a-z0-9]$',
            $schema['$defs']['surfaceIdentifier']['pattern'] ?? null,
        );
        self::assertSame('#/$defs/surfaceIdentifier', $schema['$defs']['dottedName']['$ref'] ?? null);
        self::assertSame(
            [
                ['$ref' => '#/$defs/semanticName'],
                ['$ref' => '#/$defs/dottedName'],
            ],
            $schema['$defs']['dashboardIdentifier']['anyOf'] ?? null,
        );
        self::assertSame(
            '#/$defs/dashboardIdentifier',
            $schema['$defs']['dashboardIdentifierList']['items']['$ref'] ?? null,
        );

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
