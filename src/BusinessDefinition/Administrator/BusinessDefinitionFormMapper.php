<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Administrator;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Ramsey\Uuid\Uuid;

/** Maps bounded graphical administrator controls into the strict definition document. */
final readonly class BusinessDefinitionFormMapper
{
    /** @param array<string, string> $form */
    public function definition(array $form, SiteContext $site): EntityTypeDefinition
    {
        $fields = [];
        for ($index = 0; $index < 256; ++$index) {
            $handle = $this->value($form, "field_{$index}_handle");
            if ($handle === '') {
                continue;
            }
            $type = $this->required($form, "field_{$index}_type");
            $required = $this->checked($form, "field_{$index}_required");
            $computed = $this->checked($form, "field_{$index}_computed");
            $identity = ($this->value($form, 'identity_strategy', 'uuid') === 'uuid' && $type === 'core.uuid')
                || (
                    $this->value($form, 'identity_strategy', 'uuid') === 'reference'
                    && $type === 'core.reference_identity'
                );
            $precision = $this->integerOrNull($form, "field_{$index}_precision");
            $scale = $this->integerOrNull($form, "field_{$index}_scale");
            $fields[] = [
                'handle' => $handle,
                'label' => $this->required($form, "field_{$index}_label"),
                'type' => $type,
                'description' => $this->value($form, "field_{$index}_description"),
                'required' => $required,
                'nullable' => !$required,
                'default' => $this->defaultValue($form, $index, $type),
                'length' => $this->integerOrNull($form, "field_{$index}_length"),
                'precision' => $precision,
                'scale' => $scale,
                'configuration' => $this->configuration($form, $index),
                'normalizers' => $this->list($form, "field_{$index}_normalizers"),
                'validators' => $this->validators($form, $index),
                'unique' => $identity || $this->checked($form, "field_{$index}_unique"),
                'indexed' => $identity || $this->checked($form, "field_{$index}_indexed"),
                'immutable_after_create' => $identity || $this->checked($form, "field_{$index}_immutable"),
                'server_only' => $computed || $this->checked($form, "field_{$index}_server_only"),
                'computed' => $computed,
                'read_only' => $computed || $this->checked($form, "field_{$index}_read_only"),
                'create_visible' => !$computed && !$this->checked($form, "field_{$index}_hide_create"),
                'update_visible' => !$computed && !$this->checked($form, "field_{$index}_hide_update"),
                'read_visible' => !$this->checked($form, "field_{$index}_hide_read"),
                'searchable' => $this->checked($form, "field_{$index}_searchable"),
                'filterable' => $this->checked($form, "field_{$index}_filterable"),
                'sortable' => $this->checked($form, "field_{$index}_sortable"),
                'reportable' => $this->checked($form, "field_{$index}_reportable"),
                'exportable' => $this->checked($form, "field_{$index}_exportable"),
                'sensitivity' => $this->value($form, "field_{$index}_sensitivity", 'internal'),
                'localized' => $this->checked($form, "field_{$index}_localized"),
                'help_text' => $this->value($form, "field_{$index}_help"),
                'form_group' => $this->value($form, "field_{$index}_group", 'general'),
                'order' => $this->integer($form, "field_{$index}_order", $index * 10),
                'placements' => $this->list($form, "field_{$index}_placements", ['form', 'detail']),
                'visibility_condition' => $this->condition($form, "field_{$index}_visibility"),
                'editability_condition' => $this->condition($form, "field_{$index}_editability"),
                'formula' => $computed ? $this->formula($form, $index) : null,
            ];
        }
        if ($fields === []) {
            throw new InvalidArgumentException('Add at least one field with an identity field before saving.');
        }

        $id = $this->value($form, 'id');
        return EntityTypeDefinition::fromArray([
            'id' => $id === '' ? Uuid::uuid7()->toString() : $id,
            'owner' => DefinitionOwner::site($site->identifier())->toArray(),
            'site' => $site->identifier(),
            'handle' => $this->required($form, 'handle'),
            'singular_label' => $this->required($form, 'singular_label'),
            'plural_label' => $this->required($form, 'plural_label'),
            'status' => DefinitionStatus::Draft->value,
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => $this->value($form, 'identity_strategy', 'uuid'),
            'scope' => $this->value($form, 'scope', 'site'),
            'audit_enabled' => !$this->checked($form, 'audit_disabled'),
            'revisions_enabled' => !$this->checked($form, 'revisions_disabled'),
            'soft_delete_enabled' => $this->checked($form, 'soft_delete_enabled'),
            'fields' => $fields,
            'relationships' => $this->relationships($form),
            'views' => $this->views($form),
            'actions' => $this->actions($form),
            'workflow' => $this->workflow($form),
            'compatibility_metadata' => [],
            'administrator_exposure' => !$this->checked($form, 'administrator_hidden'),
            'portal_exposure' => $this->checked($form, 'portal_exposure'),
            'public_exposure' => $this->checked($form, 'public_exposure'),
        ]);
    }

    /**
     * @param array<string, string> $form
     * @return list<array<string, mixed>>
     */
    private function relationships(array $form): array
    {
        $result = [];
        for ($index = 0; $index < 128; ++$index) {
            if (($handle = $this->value($form, "relationship_{$index}_handle")) === '') {
                continue;
            }
            $inverse = $this->value($form, "relationship_{$index}_inverse");
            $result[] = [
                'handle' => $handle,
                'label' => $this->required($form, "relationship_{$index}_label"),
                'kind' => $this->required($form, "relationship_{$index}_kind"),
                'target' => $this->required($form, "relationship_{$index}_target"),
                'inverse' => $inverse === '' ? null : $inverse,
                'required' => $this->checked($form, "relationship_{$index}_required"),
                'unique' => $this->checked($form, "relationship_{$index}_unique"),
                'ordered' => $this->checked($form, "relationship_{$index}_ordered"),
                'on_delete' => $this->value($form, "relationship_{$index}_delete", 'restrict'),
            ];
        }
        return $result;
    }

    /**
     * @param array<string, string> $form
     * @return list<array<string, mixed>>
     */
    private function views(array $form): array
    {
        $result = [];
        for ($index = 0; $index < 64; ++$index) {
            if (($handle = $this->value($form, "view_{$index}_handle")) === '') {
                continue;
            }
            $result[] = [
                'handle' => $handle,
                'label' => $this->required($form, "view_{$index}_label"),
                'kind' => $this->required($form, "view_{$index}_kind"),
                'fields' => $this->list($form, "view_{$index}_fields"),
                'filters' => $this->list($form, "view_{$index}_filters"),
                'sorts' => $this->list($form, "view_{$index}_sorts"),
                'administrator' => !$this->checked($form, "view_{$index}_administrator_hidden"),
                'portal' => $this->checked($form, "view_{$index}_portal"),
                'public' => $this->checked($form, "view_{$index}_public"),
            ];
        }
        return $result;
    }

    /**
     * @param array<string, string> $form
     * @return list<array<string, mixed>>
     */
    private function actions(array $form): array
    {
        $result = [];
        for ($index = 0; $index < 64; ++$index) {
            if (($handle = $this->value($form, "action_{$index}_handle")) === '') {
                continue;
            }
            $transition = $this->value($form, "action_{$index}_transition");
            $result[] = [
                'handle' => $handle,
                'label' => $this->required($form, "action_{$index}_label"),
                'capability' => $this->required($form, "action_{$index}_capability"),
                'bulk' => $this->checked($form, "action_{$index}_bulk"),
                'administrator' => true,
                'portal' => $this->checked($form, "action_{$index}_portal"),
                'public' => false,
                'high_impact' => $this->checked($form, "action_{$index}_high_impact"),
                'condition' => $this->condition($form, "action_{$index}_condition"),
                'transition' => $transition === '' ? null : $transition,
            ];
        }
        return $result;
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>|null
     */
    private function workflow(array $form): ?array
    {
        if (!$this->checked($form, 'workflow_enabled')) {
            return null;
        }
        $transitions = [];
        for ($index = 0; $index < 128; ++$index) {
            if (($handle = $this->value($form, "transition_{$index}_handle")) === '') {
                continue;
            }
            $transitions[] = [
                'handle' => $handle,
                'from' => $this->required($form, "transition_{$index}_from"),
                'to' => $this->required($form, "transition_{$index}_to"),
                'capability' => $this->required($form, "transition_{$index}_capability"),
            ];
        }
        return [
            'initial_state' => $this->required($form, 'workflow_initial_state'),
            'states' => $this->list($form, 'workflow_states'),
            'transitions' => $transitions,
        ];
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function formula(array $form, int $index): array
    {
        $type = $this->value($form, "field_{$index}_formula_type", 'string');
        $leftField = $this->value($form, "field_{$index}_formula_left");
        if ($leftField === '') {
            return $this->preservedExpression($form, "field_{$index}_formula")
                ?? throw new InvalidArgumentException('A computed field requires a safe formula.');
        }
        $left = ['op' => 'field', 'type' => $type, 'field' => $leftField];
        $operator = $this->value($form, "field_{$index}_formula_operator", 'field');
        if ($operator === 'field') {
            return $left;
        }
        $document = [
            'op' => $operator,
            'type' => $type,
            'args' => [$left, [
                'op' => 'field',
                'type' => $type,
                'field' => $this->required($form, "field_{$index}_formula_right"),
            ]],
        ];
        if ($operator === 'divide' && $type === 'decimal') {
            $document['scale'] = $this->integer($form, "field_{$index}_formula_scale", 2);
        }
        return $document;
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>|null
     */
    private function condition(array $form, string $prefix): ?array
    {
        $field = $this->value($form, $prefix . '_field');
        if ($field === '') {
            return $this->preservedExpression($form, $prefix);
        }
        $operator = $this->value($form, $prefix . '_operator', 'eq');
        $type = $this->value($form, $prefix . '_type', 'string');
        $arguments = [['op' => 'field', 'type' => $type, 'field' => $field]];
        if ($operator !== 'is_null') {
            $arguments[] = [
                'op' => 'literal',
                'type' => $type,
                'value' => $this->literal($this->value($form, $prefix . '_value'), $type),
            ];
        }
        return ['op' => $operator, 'type' => 'boolean', 'args' => $arguments];
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>|null
     */
    private function preservedExpression(array $form, string $prefix): ?array
    {
        $json = $this->value($form, $prefix . '_preserved');
        if ($json === '') {
            return null;
        }
        try {
            $expression = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('A preserved expression is invalid JSON.', 0, $exception);
        }
        if (!is_array($expression) || array_is_list($expression)) {
            throw new InvalidArgumentException('A preserved expression must be an object.');
        }
        /** @var array<string, mixed> $expression */
        return $expression;
    }

    private function literal(string $value, string $type): string|int|bool|null
    {
        return match ($type) {
            'null' => null,
            'boolean' => match ($value) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw new InvalidArgumentException('A boolean condition value must be true or false.'),
            },
            'integer' => $this->validatedInteger($value, 'condition value'),
            default => $value,
        };
    }

    /**
     * @param array<string, string> $form
     * @return list<array<string, mixed>>
     */
    private function validators(array $form, int $index): array
    {
        $rule = $this->value($form, "field_{$index}_validator");
        if ($rule === '') {
            $json = $this->value($form, "field_{$index}_validators_preserved");
            if ($json === '') {
                return [];
            }
            try {
                $validators = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new InvalidArgumentException('Preserved field validators are invalid JSON.', 0, $exception);
            }
            if (!is_array($validators) || !array_is_list($validators)) {
                throw new InvalidArgumentException('Preserved field validators must be a list.');
            }
            $result = [];
            foreach ($validators as $validator) {
                if (!is_array($validator) || array_is_list($validator)) {
                    throw new InvalidArgumentException('Every preserved field validator must be an object.');
                }
                /** @var array<string, mixed> $validator */
                $result[] = $validator;
            }
            return $result;
        }
        return [[
            'rule' => $rule,
            'value' => $this->value($form, "field_{$index}_validator_value"),
        ]];
    }

    /**
     * @param array<string, string> $form
     * @return array<string, scalar|list<scalar|null>|null>
     */
    private function configuration(array $form, int $index): array
    {
        $json = $this->value($form, "field_{$index}_configuration_preserved");
        try {
            $configuration = $json === '' ? [] : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Preserved field configuration is invalid JSON.', 0, $exception);
        }
        if (!is_array($configuration) || ($configuration !== [] && array_is_list($configuration))) {
            throw new InvalidArgumentException('Preserved field configuration must be an object.');
        }
        foreach (['options', 'currency', 'unit', 'target', 'max_bytes'] as $knownKey) {
            unset($configuration[$knownKey]);
        }
        $options = $this->list($form, "field_{$index}_options");
        if ($options !== []) {
            $configuration['options'] = $options;
        }
        foreach (['currency', 'unit', 'target'] as $key) {
            $value = $this->value($form, "field_{$index}_{$key}");
            if ($value !== '') {
                $configuration[$key] = $value;
            }
        }
        $maxBytes = $this->integerOrNull($form, "field_{$index}_max_bytes");
        if ($maxBytes !== null) {
            $configuration['max_bytes'] = $maxBytes;
        }
        /** @var array<string, scalar|list<scalar|null>|null> $configuration */
        return $configuration;
    }

    /** @param array<string, string> $form */
    private function defaultValue(array $form, int $index, string $type): mixed
    {
        $value = $this->value($form, "field_{$index}_default");
        if ($value === '') {
            return null;
        }
        return match ($type) {
            'core.integer' => $this->validatedInteger($value, 'field default'),
            'core.boolean' => match ($value) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw new InvalidArgumentException('A boolean default must be true or false.'),
            },
            default => $value,
        };
    }

    /**
     * @param array<string, string> $form
     * @param list<string> $default
     * @return list<string>
     */
    private function list(array $form, string $key, array $default = []): array
    {
        $value = $this->value($form, $key);
        if ($value === '') {
            return $default;
        }
        $items = preg_split('/[\r\n,]+/u', $value);
        if ($items === false) {
            $items = [];
        }
        return array_values(array_unique(array_filter(
            array_map('trim', $items),
            static fn (string $item): bool => $item !== '',
        )));
    }

    /** @param array<string, string> $form */
    private function required(array $form, string $key): string
    {
        $value = $this->value($form, $key);
        if ($value === '') {
            throw new InvalidArgumentException('The ' . str_replace('_', ' ', $key) . ' field is required.');
        }
        return $value;
    }

    /** @param array<string, string> $form */
    private function value(array $form, string $key, string $default = ''): string
    {
        return trim($form[$key] ?? $default);
    }

    /** @param array<string, string> $form */
    private function checked(array $form, string $key): bool
    {
        return ($form[$key] ?? '') === '1';
    }

    /** @param array<string, string> $form */
    private function integer(array $form, string $key, int $default): int
    {
        $value = $this->value($form, $key);
        return $value === '' ? $default : $this->validatedInteger($value, $key);
    }

    /** @param array<string, string> $form */
    private function integerOrNull(array $form, string $key): ?int
    {
        $value = $this->value($form, $key);
        return $value === '' ? null : $this->validatedInteger($value, $key);
    }

    private function validatedInteger(string $value, string $key): int
    {
        if (preg_match('/^-?[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('The ' . str_replace('_', ' ', $key) . ' field must be an integer.');
        }
        return (int) $value;
    }
}
