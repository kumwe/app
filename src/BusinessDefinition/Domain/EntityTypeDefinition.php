<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

use Ramsey\Uuid\Uuid;

final readonly class EntityTypeDefinition
{
    /** @var list<FieldDefinition> */
    private array $fields;

    /** @var list<RelationshipDefinition> */
    private array $relationships;

    /** @var list<ViewDefinition> */
    private array $views;

    /** @var list<ActionDefinition> */
    private array $actions;

    /** @var list<RecordInvariantDefinition> */
    private array $recordInvariants;

    /** @var array<string, mixed> */
    private array $compatibilityMetadata;

    /**
     * @param list<FieldDefinition> $fields
     * @param list<RelationshipDefinition> $relationships
     * @param list<ViewDefinition> $views
     * @param list<ActionDefinition> $actions
     * @param array<string, mixed> $compatibilityMetadata
     * @param list<RecordInvariantDefinition> $recordInvariants
     */
    public function __construct(
        public string $id,
        public DefinitionOwner $owner,
        public string $siteIdentifier,
        public string $handle,
        public string $singularLabel,
        public string $pluralLabel,
        public DefinitionStatus $status,
        public int $definitionVersion,
        public StorageMode $storageMode,
        public IdentityStrategy $identityStrategy,
        public ScopeMode $scope,
        public bool $auditEnabled,
        public bool $revisionsEnabled,
        array $fields,
        array $relationships = [],
        array $views = [],
        array $actions = [],
        public ?WorkflowBinding $workflow = null,
        array $compatibilityMetadata = [],
        public bool $administratorExposure = true,
        public bool $portalExposure = false,
        public bool $publicExposure = false,
        public bool $softDeleteEnabled = false,
        array $recordInvariants = [],
    ) {
        if (!Uuid::isValid($id)) {
            throw new InvalidBusinessDefinition('A business entity definition ID must be a canonical UUID.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $siteIdentifier) !== 1) {
            throw new InvalidBusinessDefinition('A business entity definition site is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $handle) !== 1 || strlen($handle) > 191) {
            throw new InvalidBusinessDefinition('A business entity handle must be a stable namespaced identifier.');
        }
        $owner->assertOwns($handle);
        if (
            $singularLabel === '' || $pluralLabel === ''
            || max(strlen($singularLabel), strlen($pluralLabel)) > 120
        ) {
            throw new InvalidBusinessDefinition('A business entity requires bounded singular and plural labels.');
        }
        if ($definitionVersion < 0) {
            throw new InvalidBusinessDefinition('A business entity definition version cannot be negative.');
        }
        if ($status === DefinitionStatus::Draft && $definitionVersion !== 0) {
            throw new InvalidBusinessDefinition('A draft business definition must use definition version zero.');
        }
        if ($status !== DefinitionStatus::Draft && $definitionVersion < 1) {
            throw new InvalidBusinessDefinition('A published business definition requires a positive version.');
        }
        if (
            $fields === [] || count($fields) > 256 || count($relationships) > 128
            || count($views) > 64 || count($actions) > 64
        ) {
            throw new InvalidBusinessDefinition('A business entity definition collection is empty or unbounded.');
        }
        $this->fields = self::unique($fields, static fn (FieldDefinition $field): string => $field->handle, 'field');
        $this->relationships = self::unique(
            $relationships,
            static fn (RelationshipDefinition $relationship): string => $relationship->handle,
            'relationship',
        );
        $this->views = self::unique($views, static fn (ViewDefinition $view): string => $view->handle, 'view');
        $this->actions = self::unique(
            $actions,
            static fn (ActionDefinition $action): string => $action->handle,
            'action',
        );
        $this->recordInvariants = self::unique(
            $recordInvariants,
            static fn (RecordInvariantDefinition $invariant): string => $invariant->handle,
            'record invariant',
        );
        if (!$administratorExposure && !$portalExposure && !$publicExposure) {
            throw new InvalidBusinessDefinition('A business entity requires at least one declared exposure surface.');
        }
        if (
            !$portalExposure && array_filter(
                $this->views,
                static fn (ViewDefinition $view): bool => $view->portal,
            ) !== []
        ) {
            throw new InvalidBusinessDefinition('A portal view requires entity-level portal exposure.');
        }
        if (
            !$publicExposure && array_filter(
                $this->views,
                static fn (ViewDefinition $view): bool => $view->public,
            ) !== []
        ) {
            throw new InvalidBusinessDefinition('A public view requires entity-level public exposure.');
        }
        CanonicalDefinitionJson::encode($compatibilityMetadata);
        $this->compatibilityMetadata = $compatibilityMetadata;
        $this->assertInternalGraph();
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        $allowed = [
            'id', 'owner', 'site', 'handle', 'singular_label', 'plural_label', 'status', 'definition_version',
            'storage_mode', 'identity_strategy', 'scope', 'audit_enabled', 'revisions_enabled', 'fields',
            'relationships', 'views', 'actions', 'workflow', 'compatibility_metadata', 'administrator_exposure',
            'portal_exposure', 'public_exposure', 'soft_delete_enabled', 'record_invariants',
        ];
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidBusinessDefinition('A business entity definition contains an unknown property.');
        }
        $owner = $document['owner'] ?? null;
        if (
            !is_array($owner) || array_is_list($owner)
            || array_diff(array_keys($owner), ['type', 'identifier']) !== []
        ) {
            throw new InvalidBusinessDefinition('A business entity definition owner must be a strict object.');
        }
        /** @var array<string, mixed> $owner */
        $ownerType = DefinitionOwnerType::tryFrom(self::string($owner, 'type'))
            ?? throw new InvalidBusinessDefinition('A business entity definition owner type is invalid.');
        $fields = array_map(
            static fn (array $field): FieldDefinition => FieldDefinition::fromArray($field),
            self::objects($document, 'fields'),
        );
        $relationships = array_map(
            static fn (array $relationship): RelationshipDefinition => RelationshipDefinition::fromArray($relationship),
            self::objects($document, 'relationships'),
        );
        $views = array_map(
            static fn (array $view): ViewDefinition => ViewDefinition::fromArray($view),
            self::objects($document, 'views'),
        );
        $actions = array_map(
            static fn (array $action): ActionDefinition => ActionDefinition::fromArray($action),
            self::objects($document, 'actions'),
        );
        $recordInvariants = array_map(
            static fn (array $invariant): RecordInvariantDefinition => RecordInvariantDefinition::fromArray($invariant),
            self::objects($document, 'record_invariants'),
        );
        $workflowDocument = $document['workflow'] ?? null;
        if ($workflowDocument !== null && (!is_array($workflowDocument) || array_is_list($workflowDocument))) {
            throw new InvalidBusinessDefinition('A business workflow binding must be an object or null.');
        }
        /** @var non-empty-array<string, mixed>|null $workflowDocument */
        $metadata = $document['compatibility_metadata'] ?? [];
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new InvalidBusinessDefinition('Business compatibility metadata must be an object.');
        }
        /** @var array<string, mixed> $metadata */

        return new self(
            self::string($document, 'id'),
            new DefinitionOwner($ownerType, self::string($owner, 'identifier')),
            self::string($document, 'site'),
            self::string($document, 'handle'),
            self::string($document, 'singular_label'),
            self::string($document, 'plural_label'),
            DefinitionStatus::tryFrom(self::string($document, 'status'))
                ?? throw new InvalidBusinessDefinition('A business definition status is invalid.'),
            self::integer($document, 'definition_version'),
            StorageMode::tryFrom(self::string($document, 'storage_mode'))
                ?? throw new InvalidBusinessDefinition('A business storage mode is invalid.'),
            IdentityStrategy::tryFrom(self::string($document, 'identity_strategy'))
                ?? throw new InvalidBusinessDefinition('A business identity strategy is invalid.'),
            ScopeMode::tryFrom(self::string($document, 'scope'))
                ?? throw new InvalidBusinessDefinition('A business scope mode is invalid.'),
            self::boolean($document, 'audit_enabled', true),
            self::boolean($document, 'revisions_enabled', true),
            $fields,
            $relationships,
            $views,
            $actions,
            is_array($workflowDocument) ? WorkflowBinding::fromArray($workflowDocument) : null,
            $metadata,
            self::boolean($document, 'administrator_exposure', true),
            self::boolean($document, 'portal_exposure'),
            self::boolean($document, 'public_exposure'),
            self::boolean($document, 'soft_delete_enabled'),
            $recordInvariants,
        );
    }

    /** @return list<FieldDefinition> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return list<RelationshipDefinition> */
    public function relationships(): array
    {
        return $this->relationships;
    }

    /**
     * Resolves both explicit relationships and the legacy field-shaped ordered-line contract.
     * Ordered lines are always an owned, ordered collection whose lifecycle follows its owner.
     */
    public function runtimeRelationship(string $handle): ?RelationshipDefinition
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->handle === $handle) {
                return $relationship;
            }
        }
        foreach ($this->fields as $field) {
            if ($field->handle !== $handle || $field->type !== 'core.ordered_lines') {
                continue;
            }
            $target = $field->configuration['target'] ?? null;
            if (!is_string($target)) {
                throw new InvalidBusinessDefinition('An ordered-line field requires a declared entity target.');
            }

            return new RelationshipDefinition(
                $field->handle,
                $field->label,
                RelationshipKind::OwnedLineCollection,
                $target,
                null,
                false,
                false,
                true,
                DeleteBehavior::Cascade,
            );
        }

        return null;
    }

    /** @return list<ViewDefinition> */
    public function views(): array
    {
        return $this->views;
    }

    /** @return list<ActionDefinition> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** @return list<RecordInvariantDefinition> */
    public function recordInvariants(): array
    {
        return $this->recordInvariants;
    }

    /** @return array<string, mixed> */
    public function compatibilityMetadata(): array
    {
        return $this->compatibilityMetadata;
    }

    public function published(int $version): self
    {
        if ($this->status !== DefinitionStatus::Draft || $version < 1) {
            throw new InvalidBusinessDefinition('Only a draft definition can be published to a positive version.');
        }

        return new self(
            $this->id,
            $this->owner,
            $this->siteIdentifier,
            $this->handle,
            $this->singularLabel,
            $this->pluralLabel,
            DefinitionStatus::Published,
            $version,
            $this->storageMode,
            $this->identityStrategy,
            $this->scope,
            $this->auditEnabled,
            $this->revisionsEnabled,
            $this->fields,
            $this->relationships,
            $this->views,
            $this->actions,
            $this->workflow,
            $this->compatibilityMetadata,
            $this->administratorExposure,
            $this->portalExposure,
            $this->publicExposure,
            $this->softDeleteEnabled,
            $this->recordInvariants,
        );
    }

    public function withStatus(DefinitionStatus $status): self
    {
        if ($status === DefinitionStatus::Draft || $this->definitionVersion < 1) {
            throw new InvalidBusinessDefinition('A published definition cannot return to draft status.');
        }

        return new self(
            $this->id,
            $this->owner,
            $this->siteIdentifier,
            $this->handle,
            $this->singularLabel,
            $this->pluralLabel,
            $status,
            $this->definitionVersion,
            $this->storageMode,
            $this->identityStrategy,
            $this->scope,
            $this->auditEnabled,
            $this->revisionsEnabled,
            $this->fields,
            $this->relationships,
            $this->views,
            $this->actions,
            $this->workflow,
            $this->compatibilityMetadata,
            $this->administratorExposure,
            $this->portalExposure,
            $this->publicExposure,
            $this->softDeleteEnabled,
            $this->recordInvariants,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $document = [
            'id' => $this->id,
            'owner' => $this->owner->toArray(),
            'site' => $this->siteIdentifier,
            'handle' => $this->handle,
            'singular_label' => $this->singularLabel,
            'plural_label' => $this->pluralLabel,
            'status' => $this->status->value,
            'definition_version' => $this->definitionVersion,
            'storage_mode' => $this->storageMode->value,
            'identity_strategy' => $this->identityStrategy->value,
            'scope' => $this->scope->value,
            'audit_enabled' => $this->auditEnabled,
            'revisions_enabled' => $this->revisionsEnabled,
            'fields' => array_map(static fn (FieldDefinition $field): array => $field->toArray(), $this->fields),
            'relationships' => array_map(
                static fn (RelationshipDefinition $relationship): array => $relationship->toArray(),
                $this->relationships,
            ),
            'views' => array_map(static fn (ViewDefinition $view): array => $view->toArray(), $this->views),
            'actions' => array_map(static fn (ActionDefinition $action): array => $action->toArray(), $this->actions),
            'workflow' => $this->workflow?->toArray(),
            'compatibility_metadata' => $this->compatibilityMetadata,
            'administrator_exposure' => $this->administratorExposure,
            'portal_exposure' => $this->portalExposure,
            'public_exposure' => $this->publicExposure,
        ];
        // Preserve the canonical bytes/checksums of Session-2 definitions, where
        // the absent property already meant hard-delete-only behavior.
        if ($this->softDeleteEnabled) {
            $document['soft_delete_enabled'] = true;
        }
        if ($this->recordInvariants !== []) {
            $document['record_invariants'] = array_map(
                static fn (RecordInvariantDefinition $invariant): array => $invariant->toArray(),
                $this->recordInvariants,
            );
        }

        return $document;
    }

    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }

    /** @return array{fields: array<string, list<string>>, entities: list<string>, field_types: list<string>} */
    public function dependencyGraph(): array
    {
        $fieldDependencies = [];
        foreach ($this->fields as $field) {
            $dependencies = [];
            foreach ([$field->formula, $field->visibilityCondition, $field->editabilityCondition] as $expression) {
                if ($expression !== null) {
                    array_push($dependencies, ...$expression->dependencies());
                }
            }
            $dependencies = array_values(array_unique($dependencies));
            sort($dependencies, SORT_STRING);
            $fieldDependencies[$field->handle] = $dependencies;
        }
        ksort($fieldDependencies, SORT_STRING);
        $relations = array_values(array_unique(array_map(
            static fn (RelationshipDefinition $relationship): string => $relationship->target,
            $this->relationships,
        )));
        foreach ($this->fields as $field) {
            if (!in_array($field->type, ['core.entity_reference', 'core.ordered_lines'], true)) {
                continue;
            }
            $target = $field->configuration['target'] ?? null;
            if (is_string($target)) {
                $relations[] = $target;
            }
        }
        $relations = array_values(array_unique($relations));
        sort($relations, SORT_STRING);
        $types = array_values(array_unique(array_map(
            static fn (FieldDefinition $field): string => $field->type,
            $this->fields,
        )));
        sort($types, SORT_STRING);

        return ['fields' => $fieldDependencies, 'entities' => $relations, 'field_types' => $types];
    }

    private function assertInternalGraph(): void
    {
        $fields = [];
        foreach ($this->fields as $field) {
            $fields[$field->handle] = $field;
        }
        $identityType = $this->identityStrategy === IdentityStrategy::Uuid ? 'core.uuid' : 'core.reference_identity';
        $identityFields = array_values(array_filter(
            $this->fields,
            static fn (FieldDefinition $field): bool => $field->type === $identityType,
        ));
        if (count($identityFields) !== 1) {
            throw new InvalidBusinessDefinition(
                'A business definition requires exactly one field matching its identity strategy.',
            );
        }
        foreach ($this->fields as $field) {
            if ($field->type === 'core.ordered_lines') {
                foreach ($this->relationships as $relationship) {
                    if ($relationship->handle === $field->handle) {
                        throw new InvalidBusinessDefinition(
                            'An ordered-line field and relationship cannot share a handle.',
                        );
                    }
                }
            }
            foreach ([$field->formula, $field->visibilityCondition, $field->editabilityCondition] as $expression) {
                foreach ($expression?->dependencies() ?? [] as $dependency) {
                    if (!isset($fields[$dependency])) {
                        throw new InvalidBusinessDefinition(sprintf(
                            'Business field %s depends on missing field %s.',
                            $field->handle,
                            $dependency,
                        ));
                    }
                }
            }
        }
        $this->assertNoFieldCycle($fields);
        foreach ($this->views as $view) {
            foreach (array_merge($view->fields, $view->filters, $view->sorts) as $field) {
                if (!isset($fields[$field])) {
                    throw new InvalidBusinessDefinition('A business view references missing field ' . $field . '.');
                }
            }
            foreach ($view->filters as $field) {
                if (!$fields[$field]->filterable) {
                    throw new InvalidBusinessDefinition('A business view filters a non-filterable field.');
                }
            }
            foreach ($view->sorts as $field) {
                if (!$fields[$field]->sortable) {
                    throw new InvalidBusinessDefinition('A business view sorts a non-sortable field.');
                }
            }
        }
        $transitions = [];
        foreach ($this->workflow->transitions ?? [] as $transition) {
            $transitions[$transition['handle']] = true;
        }
        foreach ($this->actions as $action) {
            if ($action->transition !== null && !isset($transitions[$action->transition])) {
                throw new InvalidBusinessDefinition('A business action references a missing workflow transition.');
            }
            foreach ($action->condition?->dependencies() ?? [] as $dependency) {
                if (!isset($fields[$dependency])) {
                    throw new InvalidBusinessDefinition('A business action condition references a missing field.');
                }
            }
        }
        foreach ($this->recordInvariants as $invariant) {
            foreach ($invariant->condition->dependencies() as $dependency) {
                if (!isset($fields[$dependency])) {
                    throw new InvalidBusinessDefinition('A record invariant references a missing field.');
                }
            }
        }
    }

    /** @param array<string, FieldDefinition> $fields */
    private function assertNoFieldCycle(array $fields): void
    {
        $visiting = [];
        $visited = [];
        $walk = function (string $handle) use (&$walk, &$visiting, &$visited, $fields): void {
            if (isset($visiting[$handle])) {
                throw new InvalidBusinessDefinition(
                    'A business field dependency cycle was detected at ' . $handle . '.',
                );
            }
            if (isset($visited[$handle])) {
                return;
            }
            $visiting[$handle] = true;
            foreach (
                [
                $fields[$handle]->formula,
                $fields[$handle]->visibilityCondition,
                $fields[$handle]->editabilityCondition,
                ] as $expression
            ) {
                foreach ($expression?->dependencies() ?? [] as $dependency) {
                    $walk($dependency);
                }
            }
            unset($visiting[$handle]);
            $visited[$handle] = true;
        };
        foreach (array_keys($fields) as $handle) {
            $walk($handle);
        }
    }

    /**
     * @template T of object
     * @param list<T> $values
     * @param callable(T): string $identifier
     * @return list<T>
     */
    private static function unique(array $values, callable $identifier, string $kind): array
    {
        $seen = [];
        foreach ($values as $value) {
            $key = $identifier($value);
            if (isset($seen[$key])) {
                throw new InvalidBusinessDefinition(sprintf('Business %s %s is duplicated.', $kind, $key));
            }
            $seen[$key] = true;
        }
        return $values;
    }

    /** @param array<string, mixed> $document */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Business entity property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $document */
    private static function integer(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidBusinessDefinition('Business entity property ' . $key . ' must be an integer.');
        }
        return $value;
    }

    /** @param array<string, mixed> $document */
    private static function boolean(array $document, string $key, bool $default = false): bool
    {
        $value = $document[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('Business entity property ' . $key . ' must be boolean.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $document
     * @return list<array<string, mixed>>
     */
    private static function objects(array $document, string $key): array
    {
        $value = $document[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business entity property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidBusinessDefinition('Business entity property ' . $key . ' must contain objects.');
            }
            /** @var array<string, mixed> $item */
            $result[] = $item;
        }
        return $result;
    }
}
