<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\App\BusinessDefinition\Domain\ExpressionEvaluator;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationInputFactory;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationConfiguration;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Host-side admission and execution the App composes around the package's entity definitions.
 *
 * The definition model, its validator and its canonical profile belong to `kumwe/business-definition`, which
 * owns their tests; what stays here is the SDK presentation-profile admission the App binds into the
 * validator and the PHP executor that still judges record invariants until the native cutover.
 *
 * @since  2.0.0
 */
#[CoversClass(SdkFieldConfigurationAdmission::class)]
#[CoversClass(FieldPresentationInputFactory::class)]
#[CoversClass(ExpressionEvaluator::class)]
final class EntityTypeDefinitionTest extends TestCase
{
    /**
     * Definition admission enforces the SDK's individual, list and total configuration budgets.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFieldPresentationConfigurationBudgetsFailAtDefinitionAdmission(): void
    {
        $hostile = [
            [
                'handle' => 'oversized_unit',
                'label' => 'Oversized unit',
                'type' => 'core.quantity',
                'precision' => 12,
                'scale' => 3,
                'configuration' => [
                    'unit' => str_repeat('x', FieldPresentationConfiguration::MAXIMUM_STRING_BYTES + 1),
                ],
            ],
            [
                'handle' => 'oversized_options',
                'label' => 'Oversized options',
                'type' => 'core.enum',
                'configuration' => [
                    'options' => array_fill(
                        0,
                        FieldPresentationConfiguration::MAXIMUM_LIST_ITEMS + 1,
                        'option',
                    ),
                ],
            ],
            [
                'handle' => 'oversized_total',
                'label' => 'Oversized total',
                'type' => 'core.enum',
                'configuration' => [
                    'options' => array_fill(
                        0,
                        9,
                        str_repeat('x', FieldPresentationConfiguration::MAXIMUM_STRING_BYTES),
                    ),
                ],
            ],
        ];

        foreach ($hostile as $field) {
            $document = self::document();
            $document['fields'][] = $field;
            try {
                $definition = EntityTypeDefinition::fromArray($document);
                (new BusinessDefinitionValidator(new FieldTypeRegistry(), new SdkFieldConfigurationAdmission()))
                    ->validateGraph([$definition]);
                self::fail('An unbounded field presentation configuration reached publication.');
            } catch (InvalidBusinessDefinition $exception) {
                self::assertStringContainsString('configuration', $exception->getMessage());
            }
        }
    }

    /**
     * A valid enum configuration survives admission and enters the canonical presenter input unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testValidFieldPresentationConfigurationRoundTripsIntoTheSdkInput(): void
    {
        $document = self::document();
        $document['fields'][] = [
            'handle' => 'status',
            'label' => 'Status',
            'type' => 'core.enum',
            'configuration' => ['options' => ['draft', 'published']],
        ];
        $definition = EntityTypeDefinition::fromArray($document);
        $types = new FieldTypeRegistry();
        (new BusinessDefinitionValidator($types, new SdkFieldConfigurationAdmission()))->validateGraph([$definition]);
        $field = array_values(array_filter(
            $definition->fields(),
            static fn (FieldDefinition $candidate): bool => $candidate->handle === 'status',
        ))[0];

        $input = FieldPresentationInputFactory::fromDefinition(
            $field,
            $types->get('core.enum'),
            FieldPresentationContext::Detail,
        );

        self::assertSame(['options' => ['draft', 'published']], $input->configuration->toArray());
    }

    /**
     * A record invariant's condition is judged by the App's PHP executor until the native cutover.
     *
     * The package owns the invariant's shape and refuses a non-boolean condition at construction; the host
     * still runs the condition against record values through `ExpressionEvaluator`, which is what
     * `RecordRuleValidator` does on every create and update, and an unsupplied dependency is refused rather
     * than read as null.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordInvariantConditionsAreJudgedByTheHostExecutor(): void
    {
        $document = self::document();
        $document['record_invariants'] = [[
            'handle' => 'name_present',
            'message' => 'Name must not be empty.',
            'condition' => [
                'op' => 'ne',
                'type' => 'boolean',
                'args' => [
                    ['op' => 'field', 'type' => 'string', 'field' => 'name'],
                    ['op' => 'literal', 'type' => 'string', 'value' => ''],
                ],
            ],
        ]];
        $invariant = EntityTypeDefinition::fromArray($document)->recordInvariants()[0];

        self::assertTrue(ExpressionEvaluator::evaluate($invariant->condition, ['name' => 'Asset']));
        self::assertFalse(ExpressionEvaluator::evaluate($invariant->condition, ['name' => '']));

        $this->expectException(InvalidBusinessDefinition::class);
        ExpressionEvaluator::evaluate($invariant->condition, []);
    }

    /**
     * The host-composed validator admits an unindexed text field at its full thousand-character length.
     *
     * The compiler test that pinned this before the schema extraction asserted the portable length boundary
     * on the compiled column, which `kumwe/business-schema` now proves. What stays with the host is that the
     * validator, composed with the SDK admission adapter, neither caps nor refuses a 1000-character text
     * field that is not indexed, so the declared length is what reaches the compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnindexedTextFieldKeepsItsThousandCharacterLengthThroughAdmission(): void
    {
        $document = self::document();
        $document['fields'][] = [
            'handle' => 'external_reference',
            'label' => 'External reference',
            'type' => 'core.text',
            'length' => 1000,
            'indexed' => false,
        ];
        $definition = EntityTypeDefinition::fromArray($document);

        (new BusinessDefinitionValidator(new FieldTypeRegistry(), new SdkFieldConfigurationAdmission()))
            ->validateGraph([$definition]);

        $lengths = [];
        foreach ($definition->fields() as $field) {
            $lengths[$field->handle] = $field->length;
        }
        self::assertSame(1000, $lengths['external_reference'] ?? null);
    }

    /**
     * A minimal valid site-owned definition the presentation-profile and executor cases extend.
     *
     * @return  array<string, mixed>  Canonical definition document with an identity, a text and a computed
     *          field.
     *
     * @since   2.0.0
     */
    public static function document(): array
    {
        return [
            'id' => '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.asset',
            'singular_label' => 'Asset',
            'plural_label' => 'Assets',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
                    'handle' => 'id',
                    'label' => 'ID',
                    'type' => 'core.uuid',
                    'required' => true,
                    'nullable' => false,
                    'unique' => true,
                    'indexed' => true,
                    'immutable_after_create' => true,
                    'server_only' => true,
                    'read_only' => true,
                ],
                [
                    'handle' => 'name',
                    'label' => 'Name',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 160,
                    'searchable' => true,
                    'filterable' => true,
                    'sortable' => true,
                ],
                [
                    'handle' => 'normalized_name',
                    'label' => 'Normalized name',
                    'type' => 'core.computed',
                    'nullable' => true,
                    'server_only' => true,
                    'computed' => true,
                    'read_only' => true,
                    'formula' => [
                        'op' => 'field',
                        'type' => 'string',
                        'field' => 'name',
                    ],
                ],
            ],
            'relationships' => [],
            'views' => [[
                'handle' => 'list',
                'label' => 'Assets',
                'kind' => 'list',
                'fields' => ['name'],
                'filters' => ['name'],
                'sorts' => ['name'],
                'administrator' => true,
                'portal' => false,
                'public' => false,
            ]],
            'actions' => [[
                'handle' => 'archive',
                'label' => 'Archive',
                'capability' => 'content.archive',
                'administrator' => true,
                'portal' => false,
                'public' => false,
            ]],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }
}
