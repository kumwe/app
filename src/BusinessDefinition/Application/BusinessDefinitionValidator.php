<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
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
            }
        }
        $ownershipEdges = [];
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
                if ($definition->siteIdentifier !== $target->siteIdentifier) {
                    throw new InvalidBusinessDefinition('Business relationships cannot cross site scope.');
                }
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
                    && $definition->owner != $target->owner
                ) {
                    throw new InvalidBusinessDefinition('Cascade deletion cannot cross definition ownership.');
                }
            }
        }
        $this->assertAcyclicOwnership($ownershipEdges);
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
