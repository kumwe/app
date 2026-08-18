<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\PortalOperation;
use Kumwe\CMS\BusinessDefinition\Domain\RecordInvariantDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityTypeDefinition::class)]
#[CoversClass(FieldDefinition::class)]
#[CoversClass(BusinessDefinitionValidator::class)]
#[CoversClass(FieldTypeRegistry::class)]
#[CoversClass(BuiltInFieldTypes::class)]
#[CoversClass(PortalOperation::class)]
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

    /**
     * Proves an empty portal-operation allowlist preserves legacy canonical bytes and checksum.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEmptyPortalOperationAllowlistPreservesLegacyCanonicalBytes(): void
    {
        $definition = EntityTypeDefinition::fromArray(self::document());
        $legacyDocument = $definition->toArray();
        $legacyBytes = CanonicalDefinitionJson::encode($legacyDocument);
        $legacyChecksum = CanonicalDefinitionJson::checksum($legacyDocument);
        $roundTrip = EntityTypeDefinition::fromArray($legacyDocument);

        self::assertSame([], $roundTrip->portalOperations());
        self::assertFalse($roundTrip->allowsPortalOperation(PortalOperation::Browse));
        self::assertArrayNotHasKey('portal_operations', $roundTrip->toArray());
        self::assertSame($legacyBytes, CanonicalDefinitionJson::encode($roundTrip->toArray()));
        self::assertSame($legacyChecksum, $roundTrip->checksum());

        $explicitEmptyDocument = $legacyDocument;
        $explicitEmptyDocument['portal_operations'] = [];
        self::assertSame(
            $legacyChecksum,
            EntityTypeDefinition::fromArray($explicitEmptyDocument)->checksum(),
        );
    }

    /**
     * Proves portal operations are typed, canonicalized, and retained across lifecycle copies.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalOperationAllowlistIsTypedCanonicalAndSurvivesLifecycleCopies(): void
    {
        self::assertSame([
            'action',
            'approval',
            'archive',
            'browse',
            'create',
            'delete',
            'export',
            'history',
            'read',
            'relation',
            'reorder',
            'report',
            'restore',
            'status',
            'update',
        ], array_map(static fn (PortalOperation $operation): string => $operation->value, PortalOperation::cases()));
        $document = self::document();
        $document['portal_exposure'] = true;
        $document['portal_operations'] = ['update', 'browse', 'read'];
        $definition = EntityTypeDefinition::fromArray($document);

        self::assertSame(
            [PortalOperation::Browse, PortalOperation::Read, PortalOperation::Update],
            $definition->portalOperations(),
        );
        self::assertTrue($definition->allowsPortalOperation(PortalOperation::Read));
        self::assertFalse($definition->allowsPortalOperation(PortalOperation::Create));
        self::assertSame(['browse', 'read', 'update'], $definition->toArray()['portal_operations']);

        $published = $definition->published(1);
        self::assertSame($definition->portalOperations(), $published->portalOperations());
        self::assertSame(
            $published->portalOperations(),
            $published->withStatus(DefinitionStatus::Deprecated)->portalOperations(),
        );
    }

    /**
     * Proves malformed, duplicate, unknown, and unexposed portal operations are rejected.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalOperationAllowlistRejectsUnknownDuplicateAndUnexposedOperations(): void
    {
        $invalidDocuments = [
            ['operations' => ['unknown'], 'exposure' => true, 'message' => 'invalid'],
            ['operations' => ['read', 'read'], 'exposure' => true, 'message' => 'duplicated'],
            ['operations' => ['read' => true], 'exposure' => true, 'message' => 'must be a list'],
            ['operations' => [1], 'exposure' => true, 'message' => 'must be a string'],
            ['operations' => ['read'], 'exposure' => false, 'message' => 'require entity-level portal exposure'],
        ];
        foreach ($invalidDocuments as $invalid) {
            $document = self::document();
            $document['portal_exposure'] = $invalid['exposure'];
            $document['portal_operations'] = $invalid['operations'];
            try {
                EntityTypeDefinition::fromArray($document);
                self::fail('An invalid portal-operation allowlist was accepted.');
            } catch (InvalidBusinessDefinition $exception) {
                self::assertStringContainsString($invalid['message'], $exception->getMessage());
            }
        }
    }

    public function testComputedFieldRequiresServerOnlyReadOnlyFormula(): void
    {
        $document = self::document();
        $document['fields'][2]['server_only'] = false;

        $this->expectException(InvalidBusinessDefinition::class);
        $definition = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$definition]);
    }

    /**
     * Proves a conditionally visible field cannot be queried, reported, or exported as an inference channel.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConditionallyVisibleFieldCannotExposeAQueryOrExportInferenceChannel(): void
    {
        $document = self::document();
        $document['fields'][1]['visibility_condition'] = [
            'op' => 'eq',
            'type' => 'boolean',
            'args' => [
                ['op' => 'literal', 'type' => 'string', 'value' => 'hidden'],
                ['op' => 'literal', 'type' => 'string', 'value' => 'visible'],
            ],
        ];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('cannot be queried, reported, or exported');

        EntityTypeDefinition::fromArray($document);
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
        $document['fields'][] = [
            'handle' => 'runtime_relation_evidence',
            'label' => 'Runtime relation evidence',
            'type' => 'core.text',
        ];

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
        foreach (
            [
            ['core.integer', 'not-an-integer'],
            ['core.integer', 2_147_483_648],
            ['core.decimal', '1.0000001'],
            ['core.date', 'not-a-date'],
            ['core.local_time', '25:00:00'],
            ['core.instant', '2026-08-08T11:14:15+02:00'],
            ['core.email', 'not-an-email'],
            ] as $offset => [$type, $default]
        ) {
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
        foreach (
            [
            ['core.reference_identity', 192, []],
            ['core.text', 1001, []],
            ['core.email', 321, []],
            ['core.phone', 192, []],
            ['core.enum', 192, ['options' => ['one']]],
            ] as $offset => [$type, $length, $configuration]
        ) {
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

    /**
     * Proves an entity and its fields read in a locale, and fall back to the text they declare.
     *
     * The wording an operator sees is the only translatable part of a definition, and it is resolved
     * through the requested locale's own fallback chain — `pt-BR` before `pt` — so a definition label
     * and the interface around it agree about what counts as close enough. Whatever the chain fails to
     * find resolves to the declared text, which is why every member still has wording in every locale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEntityAndFieldWordingResolveThroughTheLocaleChainAndThenToTheDeclaredText(): void
    {
        $document = self::document();
        $document['label_translations'] = [
            'singular_label' => ['de' => 'Anlage', 'pt' => 'Ativo'],
            'plural_label' => ['de' => 'Anlagen'],
        ];
        $document['fields'][1]['description'] = 'Display name of the asset.';
        $document['fields'][1]['help_text'] = 'Shown in every listing.';
        $document['fields'][1]['text_translations'] = [
            'label' => ['de' => 'Bezeichnung'],
            'description' => ['de' => 'Anzeigename der Anlage.'],
            'help_text' => ['de' => 'Wird in jeder Liste gezeigt.'],
        ];

        $definition = EntityTypeDefinition::fromArray($document);

        self::assertSame('Anlage', $definition->singularLabelIn('de'));
        self::assertSame('Ativo', $definition->singularLabelIn('pt-BR'));
        self::assertSame('Asset', $definition->singularLabelIn('af'));
        self::assertSame('Anlagen', $definition->pluralLabelIn('de'));
        self::assertSame('Assets', $definition->pluralLabelIn('pt'));
        self::assertSame(
            ['plural_label' => ['de' => 'Anlagen'], 'singular_label' => ['de' => 'Anlage', 'pt' => 'Ativo']],
            $definition->labelTranslations(),
        );

        $field = $definition->fields()[1];
        self::assertSame('Bezeichnung', $field->labelIn('de'));
        self::assertSame('Name', $field->labelIn('af'));
        self::assertSame('Anzeigename der Anlage.', $field->descriptionIn('de'));
        self::assertSame('Display name of the asset.', $field->descriptionIn('af'));
        self::assertSame('Wird in jeder Liste gezeigt.', $field->helpTextIn('de'));
        self::assertSame('Shown in every listing.', $field->helpTextIn('af'));
    }

    /**
     * Proves both translation dimensions survive the canonical document a checksum is taken over.
     *
     * A published version is immutable and identified by the digest of its bytes, so a definition that
     * carries translations has to re-encode to the same document it was rebuilt from — otherwise a
     * re-verification of an already published version would fail on wording alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTranslatedWordingSurvivesTheCanonicalDocumentAndItsChecksum(): void
    {
        $document = self::document();
        $document['label_translations'] = ['singular_label' => ['de' => 'Anlage']];
        $document['fields'][1]['text_translations'] = ['label' => ['de' => 'Bezeichnung']];
        $definition = EntityTypeDefinition::fromArray($document);

        $exported = $definition->toArray();
        self::assertSame(['singular_label' => ['de' => 'Anlage']], $exported['label_translations']);
        self::assertSame(['label' => ['de' => 'Bezeichnung']], $exported['fields'][1]['text_translations']);

        $reloaded = EntityTypeDefinition::fromArray($exported);
        self::assertSame($exported, $reloaded->toArray());
        self::assertSame($definition->checksum(), $reloaded->checksum());
        self::assertNotSame(EntityTypeDefinition::fromArray(self::document())->checksum(), $definition->checksum());
    }

    /**
     * Proves a translation map arriving as a list is refused on the entity and on a field alike.
     *
     * A locale-keyed object decoded from a document can arrive as a list when the author wrote an
     * array of strings, and taking it would put positional keys where language tags belong.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAListShapedTranslationMapIsRefusedOnTheEntityAndOnItsFields(): void
    {
        $entityDocument = self::document();
        $entityDocument['label_translations'] = ['Anlage'];

        try {
            EntityTypeDefinition::fromArray($entityDocument);
            self::fail('An entity accepted label translations declared as a list.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('label translations must be an object', $exception->getMessage());
        }

        $fieldDocument = self::document();
        $fieldDocument['fields'][1]['text_translations'] = ['Bezeichnung'];

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('text translations must be an object');
        EntityTypeDefinition::fromArray($fieldDocument);
    }

    /**
     * A definition names its posting date by declaring it on exactly one date field, or not at all.
     *
     * `posting_date` is the whole opt-in of the temporal posting lock, so three properties are pinned:
     * an undeclared definition answers null and stays untouched by the mechanism, a declared one
     * answers the single declared field, and a second declaration is refused rather than leaving the
     * lock to guess which date governs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePostingDateDeclarationNamesExactlyOneDateField(): void
    {
        $undeclared = EntityTypeDefinition::fromArray(self::document());
        self::assertNull($undeclared->postingDateField());

        $document = self::document();
        $document['fields'][] = [
            'handle' => 'posted_on',
            'label' => 'Posted on',
            'type' => 'core.date',
            'nullable' => true,
            'configuration' => ['posting_date' => true],
        ];
        $declared = EntityTypeDefinition::fromArray($document);
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$declared]);
        self::assertSame('posted_on', $declared->postingDateField()?->handle);

        $document['fields'][] = [
            'handle' => 'also_posted_on',
            'label' => 'Also posted on',
            'type' => 'core.instant',
            'nullable' => true,
            'configuration' => ['posting_date' => true],
        ];
        $twice = EntityTypeDefinition::fromArray($document);
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('more than one posting date field');
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([$twice]);
    }

    /**
     * The posting-date declaration is a boolean on a date-carrying type, and nothing else.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePostingDateDeclarationIsABooleanOnADateCarryingType(): void
    {
        $mistyped = self::document();
        $mistyped['fields'][] = [
            'handle' => 'posted_on',
            'label' => 'Posted on',
            'type' => 'core.date',
            'nullable' => true,
            'configuration' => ['posting_date' => 'yes'],
        ];
        try {
            (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
                EntityTypeDefinition::fromArray($mistyped),
            ]);
            self::fail('A non-boolean posting date declaration was accepted.');
        } catch (InvalidBusinessDefinition $exception) {
            self::assertStringContainsString('invalid posting date declaration', $exception->getMessage());
        }

        $misplaced = self::document();
        $misplaced['fields'][] = [
            'handle' => 'posted_on',
            'label' => 'Posted on',
            'type' => 'core.text',
            'nullable' => true,
            'length' => 32,
            'configuration' => ['posting_date' => true],
        ];
        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('unsupported posting_date configuration');
        (new BusinessDefinitionValidator(new FieldTypeRegistry()))->validateGraph([
            EntityTypeDefinition::fromArray($misplaced),
        ]);
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
