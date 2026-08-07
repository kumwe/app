<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityChange;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityClassification;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\ViewDefinition;

final class BusinessDefinitionCompatibilityAnalyzer
{
    public function analyze(?EntityTypeDefinition $before, EntityTypeDefinition $draft): CompatibilityPlan
    {
        $next = ($before?->definitionVersion ?? 0) + 1;
        $published = $draft->published($next);
        $changes = [];
        if ($before === null) {
            $changes[] = new CompatibilityChange(
                '/definition',
                CompatibilityClassification::Additive,
                'Create the first published definition version.',
            );
        } else {
            $this->compareIdentity($before, $published, $changes);
            $this->compareFields($before, $published, $changes);
            $this->compareRelationships($before, $published, $changes);
            $this->compareNamedDocuments($before, $published, $changes);
        }

        return new CompatibilityPlan(
            $before?->definitionVersion,
            $next,
            $before?->checksum(),
            $published->checksum(),
            $changes,
        );
    }

    /** @param list<CompatibilityChange> $changes */
    private function compareIdentity(
        EntityTypeDefinition $before,
        EntityTypeDefinition $after,
        array &$changes,
    ): void {
        foreach (
            [
                'storage_mode' => [$before->storageMode->value, $after->storageMode->value],
                'identity_strategy' => [$before->identityStrategy->value, $after->identityStrategy->value],
                'scope' => [$before->scope->value, $after->scope->value],
            ] as $key => [$old, $new]
        ) {
            if ($old !== $new) {
                $changes[] = new CompatibilityChange(
                    '/' . $key,
                    CompatibilityClassification::Destructive,
                    sprintf('Change %s from %s to %s.', str_replace('_', ' ', $key), $old, $new),
                );
            }
        }
        if (
            $before->singularLabel !== $after->singularLabel
            || $before->pluralLabel !== $after->pluralLabel
            || $before->auditEnabled !== $after->auditEnabled
            || $before->revisionsEnabled !== $after->revisionsEnabled
            || $before->compatibilityMetadata() !== $after->compatibilityMetadata()
        ) {
            $changes[] = new CompatibilityChange(
                '/definition/behavior',
                CompatibilityClassification::BehaviorChanging,
                'Change labels, audit policy, revision policy, or compatibility metadata.',
            );
        }
        foreach (
            [
                'administrator' => [$before->administratorExposure, $after->administratorExposure],
                'portal' => [$before->portalExposure, $after->portalExposure],
                'public' => [$before->publicExposure, $after->publicExposure],
            ] as $surface => [$old, $new]
        ) {
            if ($old !== $new) {
                $changes[] = new CompatibilityChange(
                    '/exposure/' . $surface,
                    CompatibilityClassification::BehaviorChanging,
                    sprintf('%s %s exposure.', $new ? 'Enable' : 'Disable', $surface),
                );
            }
        }
    }

    /** @param list<CompatibilityChange> $changes */
    private function compareFields(
        EntityTypeDefinition $before,
        EntityTypeDefinition $after,
        array &$changes,
    ): void {
        $old = self::index($before->fields(), static fn (FieldDefinition $field): string => $field->handle);
        $new = self::index($after->fields(), static fn (FieldDefinition $field): string => $field->handle);
        foreach ($old as $handle => $field) {
            if (!isset($new[$handle])) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle,
                    CompatibilityClassification::Destructive,
                    'Remove field ' . $handle . '.',
                );
                continue;
            }
            $candidate = $new[$handle];
            if ($field->type !== $candidate->type) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/type',
                    CompatibilityClassification::DataMigrationRequired,
                    sprintf('Convert field %s from %s to %s.', $handle, $field->type, $candidate->type),
                );
            }
            if (!$field->required && $candidate->required) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/required',
                    $candidate->default === null
                        ? CompatibilityClassification::DataMigrationRequired
                        : CompatibilityClassification::CompatibleConstraintTightening,
                    'Make field ' . $handle . ' required.',
                );
            } elseif ($field->required && !$candidate->required) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/required',
                    CompatibilityClassification::Additive,
                    'Make field ' . $handle . ' optional.',
                );
            }
            if ($field->nullable && !$candidate->nullable) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/nullable',
                    CompatibilityClassification::DataMigrationRequired,
                    'Disallow null values for field ' . $handle . '.',
                );
            } elseif (!$field->nullable && $candidate->nullable) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/nullable',
                    CompatibilityClassification::Additive,
                    'Allow null values for field ' . $handle . '.',
                );
            }
            $constraintsTightened = ($candidate->length !== null
                    && ($field->length === null || $candidate->length < $field->length))
                || ($candidate->precision !== null && $candidate->scale !== null
                    && $field->precision !== null && $field->scale !== null
                    && ($candidate->precision < $field->precision || $candidate->scale < $field->scale))
                || (!$field->unique && $candidate->unique);
            if ($constraintsTightened) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/constraints',
                    CompatibilityClassification::DataMigrationRequired,
                    'Tighten persisted constraints for field ' . $handle . '.',
                );
            } elseif (
                $field->length !== $candidate->length
                || $field->precision !== $candidate->precision
                || $field->scale !== $candidate->scale
                || $field->unique !== $candidate->unique
            ) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/constraints',
                    CompatibilityClassification::Additive,
                    'Relax persisted constraints for field ' . $handle . '.',
                );
            }
            if ($field->indexed !== $candidate->indexed) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/indexed',
                    CompatibilityClassification::DataMigrationRequired,
                    sprintf('%s the persisted index for field %s.', $candidate->indexed ? 'Add' : 'Remove', $handle),
                );
            }
            $removedOptions = array_diff(
                self::stringConfiguration($field, 'options'),
                self::stringConfiguration($candidate, 'options'),
            );
            if ($removedOptions !== []) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/configuration/options',
                    CompatibilityClassification::DataMigrationRequired,
                    'Remove accepted options from field ' . $handle . '.',
                );
            } elseif ($field->configuration !== $candidate->configuration) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/configuration',
                    CompatibilityClassification::BehaviorChanging,
                    'Change type-specific configuration for field ' . $handle . '.',
                );
            }
            if (
                $field->formula?->toArray() !== $candidate->formula?->toArray()
                || $field->immutableAfterCreate !== $candidate->immutableAfterCreate
                || $field->sensitivity !== $candidate->sensitivity
                || $field->default !== $candidate->default
                || $field->validators !== $candidate->validators
                || $field->normalizers !== $candidate->normalizers
            ) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/behavior',
                    CompatibilityClassification::BehaviorChanging,
                    'Change computed, immutability, or sensitivity behavior for field ' . $handle . '.',
                );
            }
            $oldPresentation = $field->toArray();
            $newPresentation = $candidate->toArray();
            foreach (
                [
                'handle', 'type', 'required', 'nullable', 'length', 'precision', 'scale', 'configuration',
                'formula', 'immutable_after_create', 'sensitivity', 'default', 'validators', 'normalizers',
                'unique', 'indexed',
                ] as $handled
            ) {
                unset($oldPresentation[$handled], $newPresentation[$handled]);
            }
            if ($oldPresentation !== $newPresentation) {
                $changes[] = new CompatibilityChange(
                    '/fields/' . $handle . '/presentation',
                    CompatibilityClassification::BehaviorChanging,
                    'Change delivery, visibility, localization, or presentation metadata for field ' . $handle . '.',
                );
            }
        }
        foreach (array_diff_key($new, $old) as $handle => $field) {
            $changes[] = new CompatibilityChange(
                '/fields/' . $handle,
                $field->required && $field->default === null
                    ? CompatibilityClassification::DataMigrationRequired
                    : CompatibilityClassification::Additive,
                'Add field ' . $handle . '.',
            );
        }
    }

    /** @return list<string> */
    private static function stringConfiguration(FieldDefinition $field, string $key): array
    {
        $value = $field->configuration[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_string'));
    }

    /** @param list<CompatibilityChange> $changes */
    private function compareRelationships(
        EntityTypeDefinition $before,
        EntityTypeDefinition $after,
        array &$changes,
    ): void {
        $old = self::index(
            $before->relationships(),
            static fn (RelationshipDefinition $relationship): string => $relationship->handle,
        );
        $new = self::index(
            $after->relationships(),
            static fn (RelationshipDefinition $relationship): string => $relationship->handle,
        );
        foreach ($old as $handle => $relationship) {
            if (!isset($new[$handle])) {
                $changes[] = new CompatibilityChange(
                    '/relationships/' . $handle,
                    CompatibilityClassification::Destructive,
                    'Remove relationship ' . $handle . '.',
                );
            } elseif ($relationship->toArray() !== $new[$handle]->toArray()) {
                $changes[] = new CompatibilityChange(
                    '/relationships/' . $handle,
                    CompatibilityClassification::DataMigrationRequired,
                    'Change relationship ' . $handle . ' cardinality, ownership, or delete behavior.',
                );
            }
        }
        foreach (array_diff_key($new, $old) as $handle => $relationship) {
            $changes[] = new CompatibilityChange(
                '/relationships/' . $handle,
                $relationship->required
                    ? CompatibilityClassification::DataMigrationRequired
                    : CompatibilityClassification::Additive,
                'Add relationship ' . $handle . '.',
            );
        }
    }

    /** @param list<CompatibilityChange> $changes */
    private function compareNamedDocuments(
        EntityTypeDefinition $before,
        EntityTypeDefinition $after,
        array &$changes,
    ): void {
        foreach (
            [
                'views' => [$before->views(), $after->views()],
                'actions' => [$before->actions(), $after->actions()],
            ] as $kind => [$oldValues, $newValues]
        ) {
            $old = self::documents($oldValues);
            $new = self::documents($newValues);
            foreach (array_diff_key($old, $new) as $handle => $_document) {
                $changes[] = new CompatibilityChange(
                    '/' . $kind . '/' . $handle,
                    CompatibilityClassification::BehaviorChanging,
                    sprintf('Remove %s %s.', rtrim($kind, 's'), $handle),
                );
            }
            foreach (array_diff_key($new, $old) as $handle => $_document) {
                $changes[] = new CompatibilityChange(
                    '/' . $kind . '/' . $handle,
                    CompatibilityClassification::Additive,
                    sprintf('Add %s %s.', rtrim($kind, 's'), $handle),
                );
            }
            foreach (array_intersect_key($old, $new) as $handle => $document) {
                if ($document !== $new[$handle]) {
                    $changes[] = new CompatibilityChange(
                        '/' . $kind . '/' . $handle,
                        CompatibilityClassification::BehaviorChanging,
                        sprintf('Change %s %s.', rtrim($kind, 's'), $handle),
                    );
                }
            }
        }
        if ($before->workflow?->toArray() !== $after->workflow?->toArray()) {
            $changes[] = new CompatibilityChange(
                '/workflow',
                CompatibilityClassification::BehaviorChanging,
                'Change the workflow binding.',
            );
        }
    }

    /**
     * @template T of object
     * @param list<T> $values
     * @param callable(T): string $key
     * @return array<string, T>
     */
    private static function index(array $values, callable $key): array
    {
        $result = [];
        foreach ($values as $value) {
            $result[$key($value)] = $value;
        }
        return $result;
    }

    /** @param list<ActionDefinition|ViewDefinition> $values @return array<string, array<string, mixed>> */
    private static function documents(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $result[$value->handle] = $value->toArray();
        }
        return $result;
    }
}
