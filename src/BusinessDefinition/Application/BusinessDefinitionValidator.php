<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;

final readonly class BusinessDefinitionValidator
{
    public function __construct(private FieldTypeRegistry $fieldTypes)
    {
    }

    /** @param list<EntityTypeDefinition> $definitions */
    public function validateGraph(array $definitions): void
    {
        if ($definitions === [] || count($definitions) > 128) {
            throw new InvalidBusinessDefinition('A business definition graph is empty or unbounded.');
        }
        $byHandle = [];
        $fieldTargets = [];
        foreach ($definitions as $definition) {
            if (isset($byHandle[$definition->handle])) {
                throw new InvalidBusinessDefinition('Business entity ' . $definition->handle . ' is duplicated.');
            }
            $byHandle[$definition->handle] = $definition;
            foreach ($definition->fields() as $field) {
                $fieldType = $this->fieldTypes->get($field->type);
                $unknown = array_diff(array_keys($field->configuration), $fieldType->configurationKeys);
                if ($unknown !== []) {
                    throw new InvalidBusinessDefinition(sprintf(
                        'Business field %s has unsupported %s configuration.',
                        $field->handle,
                        implode(', ', $unknown),
                    ));
                }
                $this->validateFieldConfiguration($field->handle, $field->configuration);
                $this->validateFieldRules($field);
                if (in_array($field->type, ['core.entity_reference', 'core.ordered_lines'], true)) {
                    $target = $field->configuration['target'] ?? null;
                    if (!is_string($target)) {
                        throw new InvalidBusinessDefinition(sprintf(
                            'Business field %s requires a declared entity target.',
                            $field->handle,
                        ));
                    }
                    $fieldTargets[] = [$definition, $field, $target];
                }
            }
        }
        $ownershipEdges = [];
        foreach ($fieldTargets as [$definition, $field, $targetHandle]) {
            $target = $byHandle[$targetHandle] ?? null;
            if (!$target instanceof EntityTypeDefinition) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Business field %s.%s targets an unavailable definition.',
                    $definition->handle,
                    $field->handle,
                ));
            }
            $this->assertCompatibleScope($definition, $target);
            if ($field->type === 'core.ordered_lines') {
                $ownershipEdges[$definition->handle][] = $target->handle;
            }
        }
        foreach ($definitions as $definition) {
            foreach ($definition->relationships() as $relationship) {
                $target = $byHandle[$relationship->target] ?? null;
                if (!$target instanceof EntityTypeDefinition) {
                    throw new InvalidBusinessDefinition(sprintf(
                        'Relationship %s.%s targets an unavailable definition.',
                        $definition->handle,
                        $relationship->handle,
                    ));
                }
                $this->assertCompatibleScope($definition, $target);
                if ($relationship->inverse !== null) {
                    $inverse = array_values(array_filter(
                        $target->relationships(),
                        static fn (RelationshipDefinition $candidate): bool =>
                            $candidate->handle === $relationship->inverse,
                    ));
                    if (count($inverse) !== 1 || $inverse[0]->target !== $definition->handle) {
                        throw new InvalidBusinessDefinition('A business relationship inverse is missing or ambiguous.');
                    }
                }
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $ownershipEdges[$definition->handle][] = $target->handle;
                }
                if (
                    $relationship->onDelete === DeleteBehavior::Cascade
                    && $relationship->kind !== RelationshipKind::OwnedLineCollection
                    && $definition->owner->toArray() !== $target->owner->toArray()
                ) {
                    throw new InvalidBusinessDefinition('Cascade deletion cannot cross definition ownership.');
                }
            }
        }
        $this->assertAcyclicOwnership($ownershipEdges);
    }

    private function assertCompatibleScope(EntityTypeDefinition $source, EntityTypeDefinition $target): void
    {
        if ($source->siteIdentifier !== $target->siteIdentifier) {
            throw new InvalidBusinessDefinition('Business references cannot cross site scope.');
        }
        if ($source->scope !== $target->scope) {
            throw new InvalidBusinessDefinition('Business references require matching scope modes.');
        }
    }

    private function validateFieldRules(FieldDefinition $field): void
    {
        $normalizers = [
            'trim', 'lowercase', 'uppercase', 'unicode_nfc', 'email', 'url', 'phone', 'decimal_scale',
        ];
        if (array_diff($field->normalizers, $normalizers) !== []) {
            throw new InvalidBusinessDefinition('Business field ' . $field->handle . ' uses an unknown normalizer.');
        }

        $allowed = [
            'pattern' => ['rule', 'value'],
            'min_length' => ['rule', 'value'],
            'max_length' => ['rule', 'value'],
            'min' => ['rule', 'value'],
            'max' => ['rule', 'value'],
            'one_of' => ['rule', 'value'],
            'email' => ['rule'],
            'url' => ['rule'],
            'uuid' => ['rule'],
            'integer' => ['rule'],
            'decimal' => ['rule'],
        ];
        foreach ($field->validators as $validator) {
            $rule = $validator['rule'] ?? null;
            if (!is_string($rule) || !isset($allowed[$rule])) {
                throw new InvalidBusinessDefinition('Business field ' . $field->handle . ' uses an unknown validator.');
            }
            $keys = array_keys($validator);
            sort($keys, SORT_STRING);
            $expected = $allowed[$rule];
            sort($expected, SORT_STRING);
            if ($keys !== $expected) {
                throw new InvalidBusinessDefinition(
                    'Business field ' . $field->handle . ' has an invalid validator shape.',
                );
            }
            $value = $validator['value'] ?? null;
            if (
                in_array($rule, ['min_length', 'max_length'], true)
                && (!is_int($value) || $value < 0 || $value > 1_000_000)
            ) {
                throw new InvalidBusinessDefinition(
                    'Business field ' . $field->handle . ' has an invalid length validator.',
                );
            }
            if (
                in_array($rule, ['min', 'max'], true)
                && (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1)
            ) {
                throw new InvalidBusinessDefinition(
                    'Business field ' . $field->handle . ' has an invalid exact numeric validator.',
                );
            }
            if (
                $rule === 'one_of'
                && (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 256)
            ) {
                throw new InvalidBusinessDefinition(
                    'Business field ' . $field->handle . ' has an invalid one-of validator.',
                );
            }
            if ($rule === 'pattern') {
                if (!is_string($value) || $value === '' || strlen($value) > 512
                    || preg_match('/\(\?(?:[=!<]|R|[0-9]|P|\()|\\\\[1-9]/', $value) === 1
                    || @preg_match('~' . str_replace('~', '\\~', $value) . '~uD', '') === false
                ) {
                    throw new InvalidBusinessDefinition(
                        'Business field ' . $field->handle . ' has an unsafe pattern validator.',
                    );
                }
            }
        }
    }

    /** @param array<string, scalar|list<scalar|null>|null> $configuration */
    private function validateFieldConfiguration(string $field, array $configuration): void
    {
        $options = $configuration['options'] ?? null;
        if ($options !== null) {
            if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > 256) {
                throw new InvalidBusinessDefinition('Business field ' . $field . ' has invalid options.');
            }
            foreach ($options as $option) {
                if (!is_string($option) || $option === '' || strlen($option) > 191) {
                    throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid option.');
                }
            }
        }
        $currency = $configuration['currency'] ?? null;
        if ($currency !== null && (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1)) {
            throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid ISO currency.');
        }
        $unit = $configuration['unit'] ?? null;
        if ($unit !== null && (!is_string($unit) || preg_match('/^[A-Za-z0-9._%\/-]{1,32}$/D', $unit) !== 1)) {
            throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid unit.');
        }
        $target = $configuration['target'] ?? null;
        if (
            $target !== null && (!is_string($target)
            || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $target) !== 1)
        ) {
            throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid entity target.');
        }
        $maxBytes = $configuration['max_bytes'] ?? null;
        if ($maxBytes !== null && (!is_int($maxBytes) || $maxBytes < 1 || $maxBytes > 1_048_576)) {
            throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid JSON byte bound.');
        }
    }

    /** @param array<string, list<string>> $edges */
    private function assertAcyclicOwnership(array $edges): void
    {
        $visiting = [];
        $visited = [];
        $walk = function (string $handle) use (&$walk, &$visiting, &$visited, $edges): void {
            if (isset($visiting[$handle])) {
                throw new InvalidBusinessDefinition('An owned relationship cycle was detected at ' . $handle . '.');
            }
            if (isset($visited[$handle])) {
                return;
            }
            $visiting[$handle] = true;
            foreach ($edges[$handle] ?? [] as $target) {
                $walk($target);
            }
            unset($visiting[$handle]);
            $visited[$handle] = true;
        };
        foreach (array_keys($edges) as $handle) {
            $walk($handle);
        }
    }
}
