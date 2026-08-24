<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use stdClass;

/**
 * Core's six canonical Studio contribution kinds and their host renderer bindings.
 *
 * @since  2.0.0
 */
final class CoreStudioCompositionContributions
{
    /**
     * Canonical core structural block types keyed by identifier and mapped to their local names.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array LAYOUT_BLOCKS = [
        'studio.core/section' => 'section',
        'studio.core/stack' => 'stack',
        'studio.core/grid' => 'grid',
        'studio.core/columns' => 'columns',
    ];

    /**
     * Canonical core Content-field block types keyed by identifier and mapped to their local names.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array FIELD_BLOCKS = [
        'core/field-text' => 'field-text',
        'core/field-rich-text' => 'field-rich-text',
        'core/field-integer' => 'field-integer',
        'core/field-decimal' => 'field-decimal',
        'core/field-boolean' => 'field-boolean',
        'core/field-date' => 'field-date',
        'core/field-date-time' => 'field-date-time',
        'core/field-media' => 'field-media',
        'core/field-resource' => 'field-resource',
    ];

    /**
     * Register canonical documents and binding metadata through core's owner-bound registrar.
     *
     * @param   OwnedExtensionContributionRegistrar  $registrar  Trusted core-owned registrar.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function register(OwnedExtensionContributionRegistrar $registrar): void
    {
        foreach (self::LAYOUT_BLOCKS as $type => $local) {
            self::document($registrar, CanonicalCompositionKind::BlockDefinition, self::layoutBlock($type, $local));
            $registrar->compositionHostBinding(new CompositionHostBinding(
                CanonicalCompositionKind::BlockDefinition,
                $type,
                'core.renderer/layout',
            ));
        }
        foreach (self::FIELD_BLOCKS as $type => $local) {
            self::document($registrar, CanonicalCompositionKind::BlockDefinition, self::fieldBlock($type, $local));
            $registrar->compositionHostBinding(new CompositionHostBinding(
                CanonicalCompositionKind::BlockDefinition,
                $type,
                'core.renderer/field',
            ));
        }
        foreach (self::otherDocuments() as [$kind, $document]) {
            $canonical = self::document($registrar, $kind, $document);
            $registrar->compositionHostBinding(new CompositionHostBinding(
                $kind,
                $canonical->identity(),
            ));
        }
    }

    /**
     * Admit one canonical composition document through the owned registry path.
     *
     * @param   OwnedExtensionContributionRegistrar  $registrar  Trusted core-owned registrar.
     * @param   CanonicalCompositionKind             $kind       Canonical document kind.
     * @param   stdClass                             $document   Canonical document value.
     *
     * @return  CanonicalCompositionDocument  The admitted canonical declaration.
     *
     * @since   2.0.0
     */
    private static function document(
        OwnedExtensionContributionRegistrar $registrar,
        CanonicalCompositionKind $kind,
        stdClass $document,
    ): CanonicalCompositionDocument {
        $canonical = new CanonicalCompositionDocument(
            $kind,
            CanonicalJson::stringify($document),
        );
        $registrar->canonicalCompositionDocument($canonical);

        return $canonical;
    }

    /**
     * Build one core Content-field block definition.
     *
     * @param   string  $type   Exact canonical block type.
     * @param   string  $local  Local field block name.
     *
     * @return  stdClass  Canonical block-definition document.
     *
     * @since   2.0.0
     */
    private static function fieldBlock(string $type, string $local): stdClass
    {
        $label = match ($local) {
            'field-boolean' => 'Yes or no',
            'field-date' => 'Date',
            'field-date-time' => 'Date and time',
            'field-decimal' => 'Decimal',
            'field-integer' => 'Integer',
            'field-media' => 'Media',
            'field-resource' => 'Resource',
            'field-rich-text' => 'Rich text',
            default => 'Text',
        };
        return (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'block-definition',
            'type' => $type,
            'version' => '1.0.0',
            'revision' => 'core-block-r1',
            'owner' => (object) ['id' => 'studio.core/blocks', 'version' => '1.0.0'],
            'label' => self::message($local, $label),
            'category' => 'core.category/content-field',
            'propertySchema' => (object) [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => new stdClass(),
            ],
            'slots' => [],
            'ports' => [(object) [
                'id' => 'value',
                'label' => self::message('value', 'Value'),
                'valueType' => str_replace('field-', '', $local),
                'required' => false,
                'multiple' => false,
            ]],
            'editingModes' => ['blueprint', 'content'],
            'themeControls' => [],
            'rendererRequirements' => [
                (object) [
                    'surface' => 'web',
                    'capability' => 'core.renderer/field',
                    'versions' => '^1.0.0',
                ],
                (object) [
                    'surface' => 'preview',
                    'capability' => 'core.renderer/field',
                    'versions' => '^1.0.0',
                ],
            ],
            'accessibility' => (object) [
                'category' => $local === 'field-media' ? 'media' : 'data-display',
                'accessibleName' => 'derived',
                'keyboard' => self::message(
                    'keyboard',
                    'Select the block, then use the inspector to choose its field binding.',
                ),
                'reducedMotion' => 'not-applicable',
                'outputChecks' => ['core.check/reflow'],
            ],
        ];
    }

    /**
     * Reproduce the coordinated Studio core layout declaration without bypassing the owned registry.
     *
     * @param   string  $type   Exact canonical block type.
     * @param   string  $local  Local layout family name.
     *
     * @return  stdClass  Canonical alpha.9 layout block document.
     *
     * @since   2.0.0
     */
    private static function layoutBlock(string $type, string $local): stdClass
    {
        $properties = [
            'alignment' => (object) ['enum' => ['center', 'end', 'start', 'stretch']],
            'spacing' => (object) ['enum' => ['comfortable', 'compact', 'none', 'spacious']],
            'visibility' => (object) ['enum' => ['hidden', 'visible']],
        ];
        $controls = ['layout-alignment', 'layout-spacing', 'layout-visibility'];
        if ($local === 'stack') {
            $properties['direction'] = (object) ['enum' => ['block', 'inline']];
            $controls[] = 'layout-direction';
        }
        if (in_array($local, ['grid', 'columns'], true)) {
            $properties['collapse'] = (object) ['enum' => ['preserve', 'stack', 'wrap']];
            $properties['columns'] = (object) ['maximum' => 12, 'minimum' => 1, 'type' => 'integer'];
            $controls[] = 'layout-collapse';
        }
        $accepted = array_merge(array_keys(self::LAYOUT_BLOCKS), array_keys(self::FIELD_BLOCKS));
        sort($accepted, SORT_STRING);

        return (object) [
            'accessibility' => (object) [
                'accessibleName' => $local === 'section' ? 'derived' : 'not-applicable',
                'category' => $local === 'section' ? 'landmark' : 'structural',
                'keyboard' => self::message(
                    'layout-keyboard',
                    'Use the outline commands to insert, move, and reorder layout children.',
                ),
                'outputChecks' => ['studio.check/reading-order', 'studio.check/reflow'],
                'reducedMotion' => 'not-applicable',
            ],
            'category' => 'studio.category/layout',
            'contractVersion' => '0.1-draft',
            'editingModes' => ['blueprint', 'content'],
            'icon' => (object) ['kind' => 'symbol', 'value' => $local],
            'kind' => 'block-definition',
            'label' => (object) [
                'key' => 'studio.blocks/' . $local,
                'defaultMessage' => ucfirst($local),
            ],
            'owner' => (object) ['id' => 'studio.core/blocks', 'version' => '1.0.0'],
            'ports' => [],
            'propertyControls' => array_map(static fn (string $control): stdClass => (object) [
                'control' => 'studio.control/' . $control,
                'property' => substr($control, strlen('layout-')),
            ], $controls),
            'propertySchema' => (object) [
                'additionalProperties' => false,
                'properties' => (object) $properties,
                'type' => 'object',
            ],
            'rendererRequirements' => [
                (object) ['surface' => 'web', 'capability' => 'core.renderer/layout', 'versions' => '^1.0.0'],
                (object) ['surface' => 'preview', 'capability' => 'core.renderer/layout', 'versions' => '^1.0.0'],
            ],
            'revision' => 'layout-' . $local . '-r1',
            'slots' => [(object) [
                'accepts' => (object) ['types' => $accepted],
                'id' => $local === 'section' ? 'content' : 'items',
                'label' => (object) [
                    'key' => $local === 'section' ? 'studio.blocks/section-content' : 'studio.blocks/layout-items',
                    'defaultMessage' => $local === 'section' ? 'Content' : 'Items',
                ],
                'maximum' => 100,
                'minimum' => 0,
                'ordered' => true,
            ]],
            'themeControls' => $controls,
            'type' => $type,
            'version' => '1.0.0',
        ];
    }

    /**
     * Build the five remaining canonical contribution kinds.
     *
     * @return  list<array{CanonicalCompositionKind, stdClass}>
     *
     * @since   2.0.0
     */
    private static function otherDocuments(): array
    {
        $owner = (object) ['id' => 'studio.core/blocks', 'version' => '1.0.0'];

        return [
            [CanonicalCompositionKind::Pattern, (object) [
                'contractVersion' => '0.1-draft', 'kind' => 'pattern',
                'id' => 'core/pattern-empty-section', 'version' => '1.0.0', 'revision' => 'pattern-r1',
                'owner' => $owner, 'label' => self::message('pattern', 'Empty section'),
                'blockDependencies' => [(object) [
                    'type' => 'studio.core/section', 'version' => '1.0.0', 'revision' => 'layout-section-r1',
                ]],
                'roots' => [(object) [
                    'id' => 'pattern-section', 'type' => 'studio.core/section', 'version' => '1.0.0',
                    'properties' => new stdClass(), 'bindings' => new stdClass(),
                    'slots' => (object) ['content' => []],
                    'authoring' => (object) ['mode' => 'structural'],
                ]],
            ]],
            [CanonicalCompositionKind::FieldAdapter, (object) [
                'contractVersion' => '0.1-draft', 'kind' => 'field-adapter',
                'id' => 'core/content-field-adapter', 'version' => '1.0.0', 'owner' => $owner,
                'label' => self::message('adapter', 'Content field'),
                'control' => 'core.control/content-field',
                'fieldKinds' => [
                    'studio.field/string',
                    'studio.field/rich-text',
                    'studio.field/integer',
                    'studio.field/decimal',
                    'studio.field/boolean',
                    'studio.field/date',
                    'studio.field/date-time',
                    'studio.field/media',
                    'studio.field/resource',
                ],
            ]],
            [CanonicalCompositionKind::Inspector, (object) [
                'contractVersion' => '0.1-draft', 'kind' => 'inspector',
                'id' => 'core/content-field-inspector', 'version' => '1.0.0', 'owner' => $owner,
                'label' => self::message('inspector', 'Content field'),
                'blockTypes' => array_keys(self::FIELD_BLOCKS), 'placement' => 'augment',
                'requiredCapability' => 'studio.permission/edit-blueprint',
            ]],
            [CanonicalCompositionKind::DesignVocabulary, (object) [
                'contractVersion' => '0.1-draft', 'kind' => 'design-vocabulary',
                'id' => 'core/content-design', 'version' => '1.0.0', 'owner' => $owner,
                'label' => self::message('design', 'Content design'),
                'designControls' => self::layoutDesignControls(), 'recipes' => self::layoutRecipes(),
            ]],
            [CanonicalCompositionKind::Migration, (object) [
                'contractVersion' => '0.1-draft', 'kind' => 'migration',
                'id' => 'core/blueprint-v1', 'version' => '1.0.0', 'owner' => $owner,
                'label' => self::message('migration', 'Blueprint version 1'),
                'artifactKinds' => ['blueprint'], 'sourceVersions' => '^1.0.0',
                'targetVersion' => '1.0.0', 'lossClassification' => 'lossless',
            ]],
        ];
    }

    /**
     * Build the five closed core layout design controls.
     *
     * @return  list<stdClass>
     *
     * @since   2.0.0
     */
    private static function layoutDesignControls(): array
    {
        return [
            self::designControl('layout-alignment', 'enum', 'Alignment', [
                ['center', 'Centre'], ['end', 'End'], ['start', 'Start'], ['stretch', 'Stretch'],
            ]),
            self::designControl('layout-collapse', 'enum', 'Responsive collapse', [
                ['preserve', 'Preserve'], ['stack', 'Stack'], ['wrap', 'Wrap'],
            ]),
            self::designControl('layout-direction', 'enum', 'Direction', [
                ['block', 'Vertical'], ['inline', 'Horizontal'],
            ]),
            self::designControl('layout-spacing', 'spacing-role', 'Spacing', [
                ['comfortable', 'Comfortable'], ['compact', 'Compact'],
                ['none', 'None'], ['spacious', 'Spacious'],
            ]),
            self::designControl('layout-visibility', 'enum', 'Visibility', [
                ['hidden', 'Hidden'], ['visible', 'Visible'],
            ]),
        ];
    }

    /**
     * Build one closed core layout design control.
     *
     * @param   string                      $id       Closed layout control ID.
     * @param   string                      $kind     Canonical theme control kind.
     * @param   string                      $label    Source label.
     * @param   list<array{string,string}>  $choices  Closed choice identifiers and labels.
     *
     * @return  stdClass  Canonical theme design control.
     *
     * @since   2.0.0
     */
    private static function designControl(string $id, string $kind, string $label, array $choices): stdClass
    {
        return (object) [
            'choices' => array_map(static fn (array $choice): stdClass => (object) [
                'id' => $choice[0],
                'label' => self::message($id . '-' . $choice[0], $choice[1]),
            ], $choices),
            'id' => $id,
            'kind' => $kind,
            'label' => self::message($id, $label),
        ];
    }

    /**
     * Build the bounded responsive layout recipe.
     *
     * @return  list<stdClass>
     *
     * @since   2.0.0
     */
    private static function layoutRecipes(): array
    {
        return [(object) [
            'blockType' => 'studio.core/grid',
            'designValues' => (object) [
                'layout-alignment' => 'stretch',
                'layout-collapse' => 'stack',
                'layout-spacing' => 'comfortable',
                'layout-visibility' => 'visible',
            ],
            'id' => 'responsive-content-grid',
            'label' => self::message('responsive-content-grid', 'Responsive content grid'),
        ]];
    }

    /**
     * Build one canonical localized label reference.
     *
     * @param   string  $key      Core-local message key suffix.
     * @param   string  $default  English source message.
     *
     * @return  stdClass  Canonical message reference.
     *
     * @since   2.0.0
     */
    private static function message(string $key, string $default): stdClass
    {
        return (object) ['key' => 'core.composition/' . $key, 'defaultMessage' => $default];
    }
}
