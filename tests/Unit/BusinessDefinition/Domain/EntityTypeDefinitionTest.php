<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RecordInvariantDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityTypeDefinition::class)]
#[CoversClass(FieldDefinition::class)]
#[CoversClass(BusinessDefinitionValidator::class)]
#[CoversClass(FieldTypeRegistry::class)]
#[CoversClass(BuiltInFieldTypes::class)]
#[CoversClass(RecordInvariantDefinition::class)]
final class EntityTypeDefinitionTest extends TestCase
{
    public function testCanonicalDefinitionProvidesStableChecksumAndDependencyGraph(): void
    {
        $definition = EntityTypeDefinition::fromArray(self::document());
        $copy = EntityTypeDefinition::fromArray(self::document());

        self::assertSame($definition->checksum(), $copy->checksum());
        self::assertSame([
            'fields' => ['id' => [], 'name' => [], 'normalized_name' => ['name']],
            'entities' => [],
            'field_types' => ['core.computed', 'core.text', 'core.uuid'],
        ], $definition->dependencyGraph());
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testComputedFieldRequiresServerOnlyReadOnlyFormula(): void
    {
        $document = self::document();
        $document['fields'][2]['server_only'] = false;

        $this->expectException(InvalidBusinessDefinition::class);
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testIdentityFieldMustRemainRequiredUniqueAndImmutable(): void
    {
        $document = self::document();
        $document['fields'][0]['immutable_after_create'] = false;

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('required, non-null, unique, and immutable');
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testFormulaDependencyCyclesFailBeforePersistence(): void
    {
        $document = self::document();
        $document['fields'][1]['computed'] = true;
        $document['fields'][1]['server_only'] = true;
        $document['fields'][1]['read_only'] = true;
        $document['fields'][1]['formula'] = [
            'op' => 'field',
            'type' => 'string',
            'field' => 'normalized_name',
        ];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('cycle');
        EntityTypeDefinition::fromArray($document);
    }

    public function testStoredComputationIsExplicitAndVirtualRemainsCanonicalDefault(): void
    {
        $virtual = EntityTypeDefinition::fromArray(self::document());
        self::assertSame(ComputationMode::Virtual, $virtual->fields()[2]->computationMode);
        self::assertArrayNotHasKey('computation_mode', $virtual->toArray()['fields'][2]);

        $document = self::document();
        $document['fields'][2]['computation_mode'] = 'stored';
        $document['fields'][2]['formula']['type'] = 'string';
        $stored = EntityTypeDefinition::fromArray($document);
        self::assertSame(ComputationMode::Stored, $stored->fields()[2]->computationMode);
        self::assertSame('stored', $stored->toArray()['fields'][2]['computation_mode']);

        $document['fields'][1]['computation_mode'] = 'stored';
        $this->expectException(InvalidBusinessDefinition::class);
        EntityTypeDefinition::fromArray($document);
    }

    public function testRecordInvariantsUseBoundedBooleanExpressionsAndKnownFields(): void
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
        $definition = EntityTypeDefinition::fromArray($document);

        self::assertTrue($definition->recordInvariants()[0]->isSatisfied(['name' => 'Asset']));
        self::assertFalse($definition->recordInvariants()[0]->isSatisfied(['name' => '']));
        self::assertSame($document['record_invariants'], $definition->toArray()['record_invariants']);

        $document['record_invariants'][0]['condition']['args'][0]['field'] = 'missing';
        $this->expectException(InvalidBusinessDefinition::class);
        EntityTypeDefinition::fromArray($document);
    }

    public function testSecretFieldRejectsAReusablePlaintextDefault(): void
    {
        $document = self::document();
        $document['fields'][] = [
            'handle' => 'credential',
            'label' => 'Credential',
            'type' => 'core.secret',
            'default' => 'must-not-be-persisted',
            'sensitivity' => 'secret',
        ];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('cannot declare a reusable plaintext default');

        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testRuntimeRevisionEvidenceHandleIsReservedAtPublication(): void
    {
        $document = self::document();
        $document['fields'][1]['handle'] = 'runtime_relation_evidence';

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('reserved for immutable runtime revision evidence');

        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testExactNumericFieldScaleCannotExceedThePortableMysqlCeiling(): void
    {
        $document = self::document();
        $document['fields'][] = [
            'handle' => 'exact_amount',
            'label' => 'Exact amount',
            'type' => 'core.decimal',
            'precision' => 65,
            'scale' => 31,
        ];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('portable DECIMAL(65, 30) bounds');

        EntityTypeDefinition::fromArray($document);
    }

    public function testEnumDefaultMustBelongToDistinctPublishedOptions(): void
    {
        $document = self::document();
        $document['fields'][] = [
            'handle' => 'status',
            'label' => 'Status',
            'type' => 'core.enum',
            'default' => 'unknown',
            'configuration' => ['options' => ['draft', 'published']],
        ];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('default must be one of its declared options');

        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testInvalidScalarAndTemporalDefaultsFailBeforeSchemaPlanning(): void
    {
        foreach ([
            ['core.integer', 'not-an-integer'],
            ['core.integer', 2_147_483_648],
            ['core.decimal', '1.0000001'],
            ['core.date', 'not-a-date'],
            ['core.local_time', '25:00:00'],
            ['core.instant', '2026-08-08T11:14:15+02:00'],
            ['core.email', 'not-an-email'],
        ] as $offset => [$type, $default]) {
            $document = self::document();
            $field = [
                'handle' => 'invalid_default_' . $offset,
                'label' => 'Invalid default',
                'type' => $type,
                'default' => $default,
            ];
            if ($type === 'core.decimal') {
                $field['precision'] = 12;
                $field['scale'] = 6;
            }
            $document['fields'][] = $field;

            try {
                $definition = EntityTypeDefinition::fromArray($document);
                (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
                self::fail('An invalid default reached schema planning for ' . $type . '.');
            } catch (InvalidBusinessDefinition $exception) {
                self::assertStringContainsString('invalid default', $exception->getMessage());
            }
        }
    }

    public function testVarcharBackedFieldsCannotExceedTheirPortablePhysicalLength(): void
    {
        foreach ([
            ['core.reference_identity', 192, []],
            ['core.text', 1001, []],
            ['core.email', 321, []],
            ['core.phone', 192, []],
            ['core.enum', 192, ['options' => ['one']]],
        ] as $offset => [$type, $length, $configuration]) {
            $document = self::document();
            $document['fields'][] = [
                'handle' => 'oversized_' . $offset,
                'label' => 'Oversized field',
                'type' => $type,
                'length' => $length,
                'configuration' => $configuration,
            ];

            try {
                $definition = EntityTypeDefinition::fromArray($document);
                (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
                self::fail('An oversized VARCHAR-backed field published for ' . $type . '.');
            } catch (InvalidBusinessDefinition $exception) {
                self::assertStringContainsString('portable physical storage limit', $exception->getMessage());
            }
        }

        $document = self::document();
        $document['fields'][2]['computation_mode'] = 'stored';
        $document['fields'][2]['length'] = 1001;
        try {
            $definition = EntityTypeDefinition::fromArray($document);
            (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
            self::fail('An oversized stored string computation was published.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('portable physical storage limit', $exception->getMessage());
        }
    }

    public function testEnumOptionsAndConfiguredUnitsMatchTheirRuntimeStorageContracts(): void
    {
        $document = self::document();
        $document['fields'][] = [
            'handle' => 'short_status',
            'label' => 'Short status',
            'type' => 'core.enum',
            'length' => 3,
            'configuration' => ['options' => ['long']],
        ];
        try {
            $definition = EntityTypeDefinition::fromArray($document);
            (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
            self::fail('An enum option longer than its physical field was published.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('option exceeds the field storage length', $exception->getMessage());
        }

        $document = self::document();
        $document['fields'][] = [
            'handle' => 'quantity',
            'label' => 'Quantity',
            'type' => 'core.quantity',
            'precision' => 12,
            'scale' => 3,
            'configuration' => ['unit' => '%'],
        ];
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('invalid unit');
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testNormalizersAndValidatorsMustMatchTheRuntimeValueFamily(): void
    {
        $document = self::document();
        $document['fields'][] = [
            'handle' => 'enabled',
            'label' => 'Enabled',
            'type' => 'core.boolean',
            'normalizers' => ['trim'],
        ];
        try {
            $definition = EntityTypeDefinition::fromArray($document);
            (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
            self::fail('A text normalizer was accepted for a boolean field.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('normalizer trim is incompatible', $exception->getMessage());
        }

        $document = self::document();
        $document['fields'][] = [
            'handle' => 'enabled',
            'label' => 'Enabled',
            'type' => 'core.boolean',
            'validators' => [['rule' => 'min', 'value' => '0']],
        ];
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('validator min is incompatible');
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testSortsRequireVisibleNonSensitiveBoundedScalarStorage(): void
    {
        $invalidFields = [
            [
                'handle' => 'long_body',
                'label' => 'Long body',
                'type' => 'core.rich_text',
                'sortable' => true,
            ],
            [
                'handle' => 'hidden_code',
                'label' => 'Hidden code',
                'type' => 'core.text',
                'read_visible' => false,
                'sortable' => true,
            ],
            [
                'handle' => 'restricted_code',
                'label' => 'Restricted code',
                'type' => 'core.text',
                'sensitivity' => 'restricted',
                'sortable' => true,
            ],
        ];
        foreach ($invalidFields as $field) {
            $document = self::document();
            $document['fields'][] = $field;
            try {
                $definition = EntityTypeDefinition::fromArray($document);
                (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
                self::fail('A cursor-leaking or unbounded sortable field was accepted.');
            } catch (InvalidBusinessDefinition $exception) {
                self::assertStringContainsString('sortable business field', strtolower($exception->getMessage()));
            }
        }
    }

    public function testOptionalNonNullFieldsRequireAnExecutableDefault(): void
    {
        $document = self::document();
        $document['fields'][] = [
            'handle' => 'non_null_optional',
            'label' => 'Non-null optional',
            'type' => 'core.text',
            'required' => false,
            'nullable' => false,
        ];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('optional non-null business field');
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testRequiredRelationshipsCannotPublishWithoutAtomicCreateInputs(): void
    {
        $document = self::document();
        $document['relationships'][] = [
            'handle' => 'parent',
            'label' => 'Parent',
            'kind' => 'many_to_one',
            'target' => $document['handle'],
            'required' => true,
        ];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('Required relationships need atomic create inputs');
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    public function testInverseRelationshipsMustBeReciprocalAndCardinalityCompatible(): void
    {
        $document = self::document();
        $document['relationships'] = [[
            'handle' => 'peers',
            'label' => 'Peers',
            'kind' => 'many_to_many',
            'target' => $document['handle'],
            'inverse' => 'parent',
        ], [
            'handle' => 'parent',
            'label' => 'Parent',
            'kind' => 'many_to_one',
            'target' => $document['handle'],
            'inverse' => 'peers',
        ]];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('cardinality-compatible');
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    /** @return array<string, mixed> */
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
