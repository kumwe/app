<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Infrastructure\Schema;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\CMS\BusinessDefinition\Domain\ScopeMode;
use Kumwe\CMS\BusinessSchema\Application\DefinitionPhysicalSchemaCompiler;
use Kumwe\CMS\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalForeignKeyBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalIndexBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\CMS\BusinessSchema\Domain\SchemaEvolutionHints;

/** Compiles immutable definition metadata into a portable, canonical physical blueprint. */
final readonly class CanonicalDefinitionPhysicalSchemaCompiler implements DefinitionPhysicalSchemaCompiler
{
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private FieldTypeDefinitionResolver $fieldTypes,
        private PhysicalNameCompiler $names,
    ) {
    }

    public function compile(EntityTypeDefinition $definition, SiteContext $site): PhysicalSchemaBlueprint
    {
        if ($definition->siteIdentifier !== $site->identifier() || $definition->definitionVersion < 1) {
            throw new InvalidBusinessSchema('Only a published definition in the requested site can be compiled.');
        }

        $record = $this->recordTable($definition, $site);
        $tables = [$record];
        foreach ($this->sortedRelationships($definition) as $relationship) {
            $target = $this->targetFor($definition, $site, $relationship->target);
            if (
                !$this->materializes($definition, $target, $relationship)
                || in_array($relationship->kind, [RelationshipKind::OneToOne, RelationshipKind::ManyToOne], true)
            ) {
                continue;
            }
            $tables[] = $relationship->kind === RelationshipKind::OwnedLineCollection
                ? $this->lineTable($definition, $target, $relationship->handle)
                : $this->junctionTable($definition, $target, $relationship);
        }
        foreach ($this->sortedFields($definition) as $field) {
            if ($field->type !== 'core.ordered_lines') {
                continue;
            }
            $target = $field->configuration['target'] ?? null;
            if (!is_string($target)) {
                throw new InvalidBusinessSchema('An ordered-line field requires a declared target definition.');
            }
            $tables[] = $this->lineTable(
                $definition,
                $this->targetFor($definition, $site, $target),
                $field->handle,
            );
        }
        usort(
            $tables,
            static fn (PhysicalTableBlueprint $left, PhysicalTableBlueprint $right): int =>
                strcmp($left->logicalName, $right->logicalName),
        );

        return new PhysicalSchemaBlueprint(
            $definition->id,
            $definition->definitionVersion,
            $definition->checksum(),
            $tables,
        );
    }

    private function recordTable(EntityTypeDefinition $definition, SiteContext $site): PhysicalTableBlueprint
    {
        $physicalTable = $this->names->entityTable($definition->id, $definition->handle);
        $columns = $this->recordControlColumns($definition);
        $indexes = [];
        $foreignKeys = [];
        $identityField = $this->identityField($definition);

        foreach ($this->sortedFields($definition) as $field) {
            if (
                ($field === $identityField && $definition->identityStrategy === IdentityStrategy::Uuid)
                || $field->type === 'core.ordered_lines'
                || ($field->computed && $field->computationMode === ComputationMode::Virtual)
            ) {
                continue;
            }
            $this->assertFieldHandleAvailable($field, [
                'record_id', 'definition_version', 'site_identifier', 'organization_identifier', 'version',
                'workflow_state', 'created_by', 'created_at', 'updated_by', 'updated_at', 'archived_by',
                'archived_at', 'deleted_by', 'deleted_at',
            ]);
            $referenceIdentity = null;
            if ($field->type === 'core.entity_reference') {
                $targetHandle = $field->configuration['target'] ?? null;
                if (!is_string($targetHandle)) {
                    throw new InvalidBusinessSchema('An entity-reference field requires a declared target.');
                }
                $referenceIdentity = $this->identityColumnFor($this->targetFor(
                    $definition,
                    $site,
                    $targetHandle,
                ));
            }
            $fieldColumns = $this->fieldColumns($field, $referenceIdentity);
            array_push($columns, ...$fieldColumns);
            $fieldPhysicalColumns = array_map(
                static fn (PhysicalColumnBlueprint $column): string => $column->physicalName,
                $fieldColumns,
            );
            $indexedColumns = [
                ...$this->scopePhysicalColumns($columns, $definition),
                ...$fieldPhysicalColumns,
            ];
            if ($field->unique || $field->indexed) {
                $this->assertIndexable($field, $fieldColumns);
                $logical = 'field.' . $field->handle;
                $indexes[] = new PhysicalIndexBlueprint(
                    $logical,
                    $this->names->index($physicalTable, $logical, $indexedColumns),
                    $indexedColumns,
                    $field->unique,
                );
            }
            if ($field->type === 'core.entity_reference') {
                $targetHandle = $field->configuration['target'] ?? null;
                if (!is_string($targetHandle)) {
                    throw new InvalidBusinessSchema('An entity-reference field requires a declared target.');
                }
                $target = $this->targetFor($definition, $site, $targetHandle);
                $targetIdentity = $this->identityColumnFor($target);
                $foreignKeys[] = new PhysicalForeignKeyBlueprint(
                    'field.' . $field->handle,
                    $this->names->foreignKey($physicalTable, 'field.' . $field->handle, $fieldPhysicalColumns),
                    $fieldPhysicalColumns,
                    $this->names->entityTable($target->id, $target->handle),
                    [$targetIdentity->physicalName],
                );
            }
        }

        foreach ($this->sortedRelationships($definition) as $relationship) {
            $target = $this->targetFor($definition, $site, $relationship->target);
            if (
                !$this->materializes($definition, $target, $relationship)
                || !in_array($relationship->kind, [RelationshipKind::OneToOne, RelationshipKind::ManyToOne], true)
            ) {
                continue;
            }
            $targetIdentity = $this->identityColumnFor($target);
            $logical = 'relation:' . $relationship->handle . '.target_id';
            $local = new PhysicalColumnBlueprint(
                $logical,
                $this->names->column($logical),
                $targetIdentity->doctrineType,
                $targetIdentity->options,
                !$relationship->required,
            );
            $columns[] = $local;
            $indexes[] = new PhysicalIndexBlueprint(
                'relation.' . $relationship->handle,
                $this->names->index($physicalTable, 'relation.' . $relationship->handle, [
                    ...$this->scopePhysicalColumns($columns, $definition),
                    $local->physicalName,
                ]),
                [...$this->scopePhysicalColumns($columns, $definition), $local->physicalName],
                $relationship->kind === RelationshipKind::OneToOne || $relationship->unique,
            );
            $foreignKeys[] = new PhysicalForeignKeyBlueprint(
                'relation.' . $relationship->handle,
                $this->names->foreignKey(
                    $physicalTable,
                    'relation.' . $relationship->handle,
                    [$local->physicalName],
                ),
                [$local->physicalName],
                $this->names->entityTable($target->id, $target->handle),
                [$targetIdentity->physicalName],
                // Singular set-null is executed by BusinessRecordService so the source receives
                // optimistic-version, revision, and audit evidence. The physical FK must restrict
                // inactive or otherwise uncoordinated source rows from being changed implicitly.
                'RESTRICT',
            );
        }

        $this->sortColumns($columns);
        $identity = $this->column($columns, 'record_id');
        $this->ensureForeignKeyIndexes(
            $physicalTable,
            $indexes,
            $foreignKeys,
        );
        $this->sortIndexes($indexes);
        $this->sortForeignKeys($foreignKeys);

        return new PhysicalTableBlueprint(
            'record',
            $physicalTable,
            PhysicalTableKind::Entity,
            $columns,
            [$identity->physicalName],
            $indexes,
            $foreignKeys,
            [
                'definition_handle' => $definition->handle,
                'identity_field' => $identityField->handle,
                'identity_strategy' => $definition->identityStrategy->value,
                'scope' => $definition->scope->value,
                'soft_delete' => $definition->softDeleteEnabled,
            ],
        );
    }

    /** @return list<PhysicalColumnBlueprint> */
    private function recordControlColumns(EntityTypeDefinition $definition): array
    {
        $columns = [
            $this->control('record_id', 'guid'),
            $this->control('definition_version', 'integer'),
            $this->control('version', 'integer', ['default' => 1]),
            $this->control('created_by', 'string', ['length' => 191]),
            $this->control('created_at', 'datetime_immutable'),
            $this->control('updated_by', 'string', ['length' => 191]),
            $this->control('updated_at', 'datetime_immutable'),
        ];
        if (in_array($definition->scope, [ScopeMode::Site, ScopeMode::SiteOrganization], true)) {
            $columns[] = $this->control('site_identifier', 'string', ['length' => 191]);
        }
        if (in_array($definition->scope, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)) {
            $columns[] = $this->control('organization_identifier', 'string', ['length' => 191]);
        }
        if ($definition->workflow !== null) {
            $columns[] = $this->control('workflow_state', 'string', ['length' => 63]);
        }
        $columns[] = $this->control('archived_by', 'string', ['length' => 191], true);
        $columns[] = $this->control('archived_at', 'datetime_immutable', [], true);
        if ($definition->softDeleteEnabled) {
            $columns[] = $this->control('deleted_by', 'string', ['length' => 191], true);
            $columns[] = $this->control('deleted_at', 'datetime_immutable', [], true);
        }

        return $columns;
    }

    private function junctionTable(
        EntityTypeDefinition $source,
        EntityTypeDefinition $target,
        RelationshipDefinition $relationship,
    ): PhysicalTableBlueprint {
        $logical = 'relation:' . $relationship->handle;
        $physicalTable = $this->names->relationTable($source->id, $relationship->handle);
        $sourceIdentity = $this->identityColumnFor($source);
        $targetIdentity = $this->identityColumnFor($target);
        $columns = [
            $this->typedControl('source_id', $sourceIdentity),
            $this->typedControl('target_id', $targetIdentity),
            $this->control('position', 'integer', [], !$relationship->ordered),
            $this->control('version', 'integer', ['default' => 1]),
            $this->control('created_by', 'string', ['length' => 191]),
            $this->control('created_at', 'datetime_immutable'),
            $this->control('updated_by', 'string', ['length' => 191]),
            $this->control('updated_at', 'datetime_immutable'),
            ...$this->scopeControlColumns($source),
        ];
        $this->sortColumns($columns);
        $sourceColumn = $this->column($columns, 'source_id');
        $targetColumn = $this->column($columns, 'target_id');
        $indexes = [new PhysicalIndexBlueprint(
            'target',
            $this->names->index($physicalTable, 'target', [
                ...$this->scopePhysicalColumns($columns, $source),
                $targetColumn->physicalName,
            ]),
            [...$this->scopePhysicalColumns($columns, $source), $targetColumn->physicalName],
            $relationship->kind === RelationshipKind::OneToMany || $relationship->unique,
        )];
        if ($relationship->ordered) {
            $position = $this->column($columns, 'position');
            $indexes[] = new PhysicalIndexBlueprint(
                'position',
                $this->names->index($physicalTable, 'position', [
                    ...$this->scopePhysicalColumns($columns, $source),
                    $sourceColumn->physicalName,
                    $position->physicalName,
                ]),
                [
                    ...$this->scopePhysicalColumns($columns, $source),
                    $sourceColumn->physicalName,
                    $position->physicalName,
                ],
                true,
            );
        }
        $foreignKeys = [
            new PhysicalForeignKeyBlueprint(
                'source',
                $this->names->foreignKey($physicalTable, 'source', [$sourceColumn->physicalName]),
                [$sourceColumn->physicalName],
                $this->names->entityTable($source->id, $source->handle),
                [$sourceIdentity->physicalName],
                'CASCADE',
            ),
            new PhysicalForeignKeyBlueprint(
                'target',
                $this->names->foreignKey($physicalTable, 'target', [$targetColumn->physicalName]),
                [$targetColumn->physicalName],
                $this->names->entityTable($target->id, $target->handle),
                [$targetIdentity->physicalName],
                $this->deleteAction($relationship->onDelete),
            ),
        ];
        $this->ensureForeignKeyIndexes(
            $physicalTable,
            $indexes,
            $foreignKeys,
        );
        $this->sortIndexes($indexes);
        $this->sortForeignKeys($foreignKeys);

        return new PhysicalTableBlueprint(
            $logical,
            $physicalTable,
            PhysicalTableKind::Junction,
            $columns,
            [$sourceColumn->physicalName, $targetColumn->physicalName],
            $indexes,
            $foreignKeys,
            ['relationship_kind' => $relationship->kind->value, 'target_definition_id' => $target->id],
        );
    }

    private function lineTable(
        EntityTypeDefinition $owner,
        EntityTypeDefinition $lineDefinition,
        string $handle,
    ): PhysicalTableBlueprint {
        $logical = 'line:' . $handle;
        $physicalTable = $this->names->lineTable($owner->id, $handle);
        $ownerIdentity = $this->identityColumnFor($owner);
        $lineIdentity = $this->identityField($lineDefinition);
        $columns = [
            $this->typedControl('owner_id', $ownerIdentity),
            $this->control(
                'line_id',
                'guid',
            ),
            $this->control('position', 'integer'),
            $this->control('version', 'integer', ['default' => 1]),
            $this->control('created_by', 'string', ['length' => 191]),
            $this->control('created_at', 'datetime_immutable'),
            $this->control('updated_by', 'string', ['length' => 191]),
            $this->control('updated_at', 'datetime_immutable'),
            ...$this->scopeControlColumns($owner),
        ];
        $indexes = [];
        $fieldForeignKeys = [];
        foreach ($this->sortedFields($lineDefinition) as $field) {
            if (
                ($field === $lineIdentity && $lineDefinition->identityStrategy === IdentityStrategy::Uuid)
                || $field->type === 'core.ordered_lines'
                || ($field->computed && $field->computationMode === ComputationMode::Virtual)
            ) {
                continue;
            }
            $this->assertFieldHandleAvailable($field, [
                'line_id', 'owner_id', 'site_identifier', 'organization_identifier', 'position', 'version',
                'created_by', 'created_at', 'updated_by', 'updated_at',
            ]);
            $referenceIdentity = null;
            $referenceTarget = null;
            if ($field->type === 'core.entity_reference') {
                $targetHandle = $field->configuration['target'] ?? null;
                if (!is_string($targetHandle)) {
                    throw new InvalidBusinessSchema('A line entity-reference field requires a declared target.');
                }
                $referenceTarget = $this->targetFor(
                    $lineDefinition,
                    SiteContext::fromString($lineDefinition->siteIdentifier),
                    $targetHandle,
                );
                $referenceIdentity = $this->identityColumnFor($referenceTarget);
            }
            $fieldColumns = $this->fieldColumns($field, $referenceIdentity);
            array_push($columns, ...$fieldColumns);
            if ($referenceTarget !== null && $referenceIdentity !== null) {
                $physicalColumns = array_map(
                    static fn (PhysicalColumnBlueprint $column): string => $column->physicalName,
                    $fieldColumns,
                );
                $fieldForeignKeys[] = new PhysicalForeignKeyBlueprint(
                    'field.' . $field->handle,
                    $this->names->foreignKey($physicalTable, 'field.' . $field->handle, $physicalColumns),
                    $physicalColumns,
                    $this->names->entityTable($referenceTarget->id, $referenceTarget->handle),
                    [$referenceIdentity->physicalName],
                );
            }
            if ($field->unique || $field->indexed) {
                $this->assertIndexable($field, $fieldColumns);
                $physical = [
                    ...$this->scopePhysicalColumns($columns, $owner),
                    ...array_map(
                        static fn (PhysicalColumnBlueprint $column): string => $column->physicalName,
                        $fieldColumns,
                    ),
                ];
                $indexLogical = 'field.' . $field->handle;
                $indexes[] = new PhysicalIndexBlueprint(
                    $indexLogical,
                    $this->names->index($physicalTable, $indexLogical, $physical),
                    $physical,
                    $field->unique,
                );
            }
        }
        $this->sortColumns($columns);
        $lineId = $this->column($columns, 'line_id');
        $ownerId = $this->column($columns, 'owner_id');
        $position = $this->column($columns, 'position');
        $indexes[] = new PhysicalIndexBlueprint(
            'owner_position',
            $this->names->index($physicalTable, 'owner_position', [
                ...$this->scopePhysicalColumns($columns, $owner),
                $ownerId->physicalName,
                $position->physicalName,
            ]),
            [
                ...$this->scopePhysicalColumns($columns, $owner),
                $ownerId->physicalName,
                $position->physicalName,
            ],
            true,
        );
        $foreignKeys = [new PhysicalForeignKeyBlueprint(
            'owner',
            $this->names->foreignKey($physicalTable, 'owner', [$ownerId->physicalName]),
            [$ownerId->physicalName],
            $this->names->entityTable($owner->id, $owner->handle),
            [$ownerIdentity->physicalName],
            'CASCADE',
        ), ...$fieldForeignKeys];
        $this->ensureForeignKeyIndexes(
            $physicalTable,
            $indexes,
            $foreignKeys,
        );
        $this->sortIndexes($indexes);
        $this->sortForeignKeys($foreignKeys);

        return new PhysicalTableBlueprint(
            $logical,
            $physicalTable,
            PhysicalTableKind::OwnedLine,
            $columns,
            [$lineId->physicalName],
            $indexes,
            $foreignKeys,
            [
                'target_definition_id' => $lineDefinition->id,
                'target_definition_version' => $lineDefinition->definitionVersion,
                'target_identity_field' => $lineIdentity->handle,
            ],
        );
    }

    /** @return list<PhysicalColumnBlueprint> */
    private function fieldColumns(
        FieldDefinition $field,
        ?PhysicalColumnBlueprint $referenceIdentity = null,
    ): array {
        $nullable = !$field->required || $field->nullable;
        $physicalDefaults = $this->physicalDefaults($field);
        /** @var \Closure(string, string, array<string, mixed>=, bool|null=): PhysicalColumnBlueprint $column */
        $column = fn (
            string $logical,
            string $type,
            array $options = [],
            ?bool $isNullable = null,
        ): PhysicalColumnBlueprint => new PhysicalColumnBlueprint(
            $logical,
            $this->names->column($logical),
            $type,
            [
                ...(array_key_exists($logical, $physicalDefaults)
                    ? ['default' => $physicalDefaults[$logical]]
                    : []),
                ...$options,
            ],
            $isNullable ?? $nullable,
        );

        return match ($field->type) {
            'core.uuid', 'core.media_reference' => [$column($field->handle, 'guid')],
            'core.entity_reference' => [$column(
                $field->handle,
                $referenceIdentity->doctrineType
                    ?? throw new InvalidBusinessSchema('An entity-reference storage mapping has no target identity.'),
                $referenceIdentity->options,
            )],
            'core.reference_identity' => [$column($field->handle, 'string', [
                'length' => min($field->length ?? 191, 191),
            ])],
            'core.text', 'core.email', 'core.phone', 'core.enum' => [$column(
                $field->handle,
                'string',
                ['length' => $this->stringLength($field)],
            )],
            'core.computed' => [$this->computedColumn($field, $column)],
            'core.rich_text', 'core.url' => [$column($field->handle, 'text')],
            'core.integer' => [$column($field->handle, 'integer')],
            'core.decimal' => [$column($field->handle, 'decimal', $this->decimalOptions($field))],
            'core.money' => [
                $column($field->handle . '.amount', 'decimal', $this->decimalOptions($field)),
                $column($field->handle . '.currency', 'ascii_string', ['length' => 3, 'fixed' => true]),
            ],
            'core.quantity' => [
                $column($field->handle . '.amount', 'decimal', $this->decimalOptions($field)),
                $column($field->handle . '.unit', 'string', ['length' => 63]),
            ],
            'core.boolean' => [$column($field->handle, 'boolean')],
            'core.date' => [$column($field->handle, 'date_immutable')],
            'core.local_time' => [$column($field->handle, 'time_immutable')],
            'core.instant' => [$column($field->handle, 'datetime_immutable')],
            'core.zoned_datetime' => [
                $column($field->handle . '.instant', 'datetime_immutable'),
                $column($field->handle . '.timezone', 'ascii_string', ['length' => 64]),
            ],
            'core.embedded_value', 'core.bounded_json' => [$column($field->handle, 'json')],
            'core.secret' => [
                $column($field->handle . '.ciphertext', 'blob'),
                $column($field->handle . '.nonce', 'binary', ['length' => 24, 'fixed' => true]),
                $column($field->handle . '.key_id', 'string', ['length' => 191]),
                $column($field->handle . '.algorithm', 'ascii_string', ['length' => 32]),
            ],
            default => [$this->customColumn($field, $column)],
        };
    }

    /** @return array<string, bool|int|string> Physical logical column to validated exact default. */
    private function physicalDefaults(FieldDefinition $field): array
    {
        if ($field->default === null) {
            return [];
        }
        if ($field->type === 'core.secret') {
            throw new InvalidBusinessSchema('An encrypted secret can never compile a plaintext database default.');
        }
        if (
            !in_array($field->type, [
            'core.boolean',
            'core.date',
            'core.decimal',
            'core.email',
            'core.enum',
            'core.instant',
            'core.integer',
            'core.local_time',
            'core.money',
            'core.phone',
            'core.quantity',
            'core.text',
            'core.zoned_datetime',
            ], true)
        ) {
            // Defaults for references, JSON/value objects, TEXT, and custom storage are applied by the
            // record service. Emitting those values as DDL defaults is not portable across all three engines.
            return [];
        }
        $components = match ($field->type) {
            'core.money' => ['amount', 'currency'],
            'core.quantity' => ['amount', 'unit'],
            'core.zoned_datetime' => ['instant', 'timezone'],
            default => null,
        };
        if ($components === null) {
            if (!is_bool($field->default) && !is_int($field->default) && !is_string($field->default)) {
                throw new InvalidBusinessSchema('A physical field default must be an exact scalar.');
            }

            return [$field->handle => $field->default];
        }
        if (
            !is_array($field->default) || array_is_list($field->default)
            || count($field->default) !== count($components)
            || array_diff(array_keys($field->default), $components) !== []
        ) {
            throw new InvalidBusinessSchema(
                'A composite field default must contain its exact ordered component document.',
            );
        }
        $result = [];
        foreach ($components as $component) {
            $value = $field->default[$component];
            if (!is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidBusinessSchema('A composite database default component must be an exact scalar.');
            }
            if ($component === 'amount') {
                $valid = is_string($value)
                    && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1;
            } elseif ($component === 'currency') {
                $valid = is_string($value) && preg_match('/^[A-Z]{3}$/D', $value) === 1;
            } elseif ($component === 'unit') {
                $valid = is_string($value) && $value !== '' && strlen($value) <= 63;
            } elseif ($component === 'instant') {
                $valid = is_string($value) && strtotime($value) !== false;
            } else {
                $valid = is_string($value) && in_array($value, timezone_identifiers_list(), true);
            }
            if (!$valid) {
                throw new InvalidBusinessSchema('A composite database default component is invalid.');
            }
            $result[$field->handle . '.' . $component] = $value;
        }

        return $result;
    }

    /** @param callable(string, string, array<string, mixed>, ?bool): PhysicalColumnBlueprint $column */
    private function customColumn(FieldDefinition $field, callable $column): PhysicalColumnBlueprint
    {
        $storage = $this->fieldTypes->get($field->type)->storageType;
        $type = match ($storage) {
            'guid' => 'guid',
            'string' => 'string',
            'text' => 'text',
            'integer' => 'integer',
            'boolean' => 'boolean',
            'date' => 'date_immutable',
            'time' => 'time_immutable',
            'datetime' => 'datetime_immutable',
            'json' => 'json',
            default => throw new InvalidBusinessSchema('A field type has no portable physical storage mapping.'),
        };
        $options = $type === 'string' ? ['length' => $this->stringLength($field)] : [];

        return $column($field->handle, $type, $options, null);
    }

    /** @param callable(string, string, array<string, mixed>, ?bool): PhysicalColumnBlueprint $column */
    private function computedColumn(FieldDefinition $field, callable $column): PhysicalColumnBlueprint
    {
        $resultType = $field->formula->type
            ?? throw new InvalidBusinessSchema('A stored computed field requires a typed formula.');
        [$type, $options] = match ($resultType) {
            'boolean' => ['boolean', []],
            'integer' => ['integer', []],
            'decimal' => ['decimal', $this->decimalOptions($field)],
            'string' => ['string', ['length' => $this->stringLength($field)]],
            'date' => ['date_immutable', []],
            'time' => ['time_immutable', []],
            'datetime' => ['datetime_immutable', []],
            default => throw new InvalidBusinessSchema('A stored formula result type has no portable DBAL mapping.'),
        };

        return $column($field->handle, $type, $options, null);
    }

    private function stringLength(FieldDefinition $field): int
    {
        return match ($field->type) {
            'core.email' => min($field->length ?? 320, 320),
            'core.phone' => min($field->length ?? 64, 191),
            'core.enum' => min($field->length ?? 191, 191),
            default => min($field->length ?? 191, 1000),
        };
    }

    /** @return array{precision: int, scale: int} */
    private function decimalOptions(FieldDefinition $field): array
    {
        if ($field->precision === null || $field->scale === null) {
            throw new InvalidBusinessSchema('An exact numeric field requires precision and scale.');
        }

        return ['precision' => $field->precision, 'scale' => $field->scale];
    }

    /** @param array<string, mixed> $options */
    private function control(
        string $logical,
        string $type,
        array $options = [],
        bool $nullable = false,
    ): PhysicalColumnBlueprint {
        return new PhysicalColumnBlueprint($logical, $this->names->column($logical), $type, $options, $nullable);
    }

    private function typedControl(string $logical, PhysicalColumnBlueprint $prototype): PhysicalColumnBlueprint
    {
        return $this->control($logical, $prototype->doctrineType, $prototype->options);
    }

    private function identityField(EntityTypeDefinition $definition): FieldDefinition
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid ? 'core.uuid' : 'core.reference_identity';
        $matches = array_values(array_filter(
            $definition->fields(),
            static fn (FieldDefinition $field): bool => $field->type === $type,
        ));
        if (count($matches) !== 1) {
            throw new InvalidBusinessSchema('A compiled definition requires exactly one identity field.');
        }
        if (
            !$matches[0]->required || $matches[0]->nullable || !$matches[0]->unique
            || !$matches[0]->immutableAfterCreate
        ) {
            throw new InvalidBusinessSchema(
                'A physical schema requires a required, non-null, unique, immutable identity field.',
            );
        }

        return $matches[0];
    }

    private function identityColumnFor(EntityTypeDefinition $definition): PhysicalColumnBlueprint
    {
        return $this->control(
            'record_id',
            'guid',
        );
    }

    private function targetFor(
        EntityTypeDefinition $source,
        SiteContext $site,
        string $handle,
    ): EntityTypeDefinition {
        $entry = $this->definitions->entry($site, $handle);
        if ($entry === null) {
            throw new InvalidBusinessSchema('A physical relationship target is unavailable: ' . $handle);
        }
        $version = SchemaEvolutionHints::fromDefinition($source)->repin($handle);
        $record = $this->definitions->published($site, $handle, $version);
        if ($record === null) {
            throw new InvalidBusinessSchema('A physical relationship target is not published: ' . $handle);
        }

        return $record->definition;
    }

    private function materializes(
        EntityTypeDefinition $source,
        EntityTypeDefinition $target,
        RelationshipDefinition $relationship,
    ): bool {
        if ($relationship->inverse === null || $relationship->kind === RelationshipKind::OwnedLineCollection) {
            return true;
        }
        $inverse = null;
        foreach ($target->relationships() as $candidate) {
            if ($candidate->handle === $relationship->inverse && $candidate->target === $source->handle) {
                $inverse = $candidate;
                break;
            }
        }
        if ($inverse === null) {
            throw new InvalidBusinessSchema('A compiled relationship inverse is unavailable.');
        }
        if (
            $relationship->kind === RelationshipKind::ManyToOne
            && $inverse->kind === RelationshipKind::OneToMany
        ) {
            return true;
        }
        if (
            $relationship->kind === RelationshipKind::OneToMany
            && $inverse->kind === RelationshipKind::ManyToOne
        ) {
            return false;
        }

        return strcmp(
            $source->handle . '#' . $relationship->handle,
            $target->handle . '#' . $inverse->handle,
        ) < 0;
    }

    /** @return list<PhysicalColumnBlueprint> */
    private function scopeControlColumns(EntityTypeDefinition $definition): array
    {
        $columns = [];
        if (in_array($definition->scope, [ScopeMode::Site, ScopeMode::SiteOrganization], true)) {
            $columns[] = $this->control('site_identifier', 'string', ['length' => 191]);
        }
        if (in_array($definition->scope, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)) {
            $columns[] = $this->control('organization_identifier', 'string', ['length' => 191]);
        }

        return $columns;
    }

    /**
     * @param list<PhysicalColumnBlueprint> $columns
     * @return list<string>
     */
    private function scopePhysicalColumns(array $columns, EntityTypeDefinition $definition): array
    {
        $physical = [];
        foreach (['site_identifier', 'organization_identifier'] as $logical) {
            if (
                ($logical === 'site_identifier'
                    && !in_array($definition->scope, [ScopeMode::Site, ScopeMode::SiteOrganization], true))
                || ($logical === 'organization_identifier'
                    && !in_array($definition->scope, [ScopeMode::Organization, ScopeMode::SiteOrganization], true))
            ) {
                continue;
            }
            $physical[] = $this->column($columns, $logical)->physicalName;
        }

        return $physical;
    }

    /** @return list<FieldDefinition> */
    private function sortedFields(EntityTypeDefinition $definition): array
    {
        $fields = $definition->fields();
        usort($fields, static fn (FieldDefinition $left, FieldDefinition $right): int =>
            strcmp($left->handle, $right->handle));

        return $fields;
    }

    /** @return list<RelationshipDefinition> */
    private function sortedRelationships(EntityTypeDefinition $definition): array
    {
        $relationships = $definition->relationships();
        usort($relationships, static fn (RelationshipDefinition $left, RelationshipDefinition $right): int =>
            strcmp($left->handle, $right->handle));

        return $relationships;
    }

    /** @param list<PhysicalColumnBlueprint> $columns */
    private function sortColumns(array &$columns): void
    {
        usort($columns, static fn (PhysicalColumnBlueprint $left, PhysicalColumnBlueprint $right): int =>
            strcmp($left->logicalName, $right->logicalName));
    }

    /** @param list<PhysicalIndexBlueprint> $indexes */
    private function sortIndexes(array &$indexes): void
    {
        usort($indexes, static fn (PhysicalIndexBlueprint $left, PhysicalIndexBlueprint $right): int =>
            strcmp($left->logicalName, $right->logicalName));
    }

    /** @param list<PhysicalForeignKeyBlueprint> $keys */
    private function sortForeignKeys(array &$keys): void
    {
        usort($keys, static fn (PhysicalForeignKeyBlueprint $left, PhysicalForeignKeyBlueprint $right): int =>
            strcmp($left->logicalName, $right->logicalName));
    }

    /**
     * MySQL and MariaDB otherwise synthesize engine-named indexes for FK columns.
     * Persisting the portable support indexes keeps all three engines' introspection exact.
     *
     * @param list<PhysicalIndexBlueprint> $indexes
     * @param list<PhysicalForeignKeyBlueprint> $foreignKeys
     */
    private function ensureForeignKeyIndexes(
        string $physicalTable,
        array &$indexes,
        array $foreignKeys,
    ): void {
        foreach ($foreignKeys as $foreignKey) {
            $supported = false;
            foreach ($indexes as $index) {
                if ($this->leftPrefix($index->columns, $foreignKey->localColumns)) {
                    $supported = true;
                    break;
                }
            }
            if ($supported) {
                continue;
            }
            $logical = 'foreign_key.' . $foreignKey->logicalName;
            $indexes[] = new PhysicalIndexBlueprint(
                $logical,
                $this->names->index($physicalTable, $logical, $foreignKey->localColumns),
                $foreignKey->localColumns,
            );
        }
    }

    /**
     * @param list<string> $columns
     * @param list<string> $prefix
     */
    private function leftPrefix(array $columns, array $prefix): bool
    {
        return array_slice($columns, 0, count($prefix)) === $prefix;
    }

    /** @param list<PhysicalColumnBlueprint> $columns */
    private function column(array $columns, string $logical): PhysicalColumnBlueprint
    {
        foreach ($columns as $column) {
            if ($column->logicalName === $logical) {
                return $column;
            }
        }
        throw new InvalidBusinessSchema('A compiled control column is missing: ' . $logical);
    }

    /** @param list<PhysicalColumnBlueprint> $columns */
    private function assertIndexable(FieldDefinition $field, array $columns): void
    {
        foreach ($columns as $column) {
            $length = $column->options['length'] ?? null;
            if (in_array($column->doctrineType, ['text', 'blob', 'json'], true) || (is_int($length) && $length > 191)) {
                throw new InvalidBusinessSchema(sprintf(
                    'Field %s cannot have a portable full-value index for its declared storage.',
                    $field->handle,
                ));
            }
        }
    }

    private function deleteAction(DeleteBehavior $behavior): string
    {
        return match ($behavior) {
            DeleteBehavior::Restrict => 'RESTRICT',
            DeleteBehavior::Cascade => 'CASCADE',
            DeleteBehavior::SetNull => 'SET NULL',
        };
    }

    /** @param list<string> $reserved */
    private function assertFieldHandleAvailable(FieldDefinition $field, array $reserved): void
    {
        if (in_array($field->handle, $reserved, true)) {
            throw new InvalidBusinessSchema(
                'Business field ' . $field->handle . ' collides with a physical runtime control column.',
            );
        }
    }
}
