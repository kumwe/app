<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityChange;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityClassification;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RecordInvariantDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\ViewDefinition;

/**
 * Prices what publishing a draft definition would do to the version already in service.
 *
 * Publishing a business definition is irreversible for the data behind it, so nothing is written before
 * every difference between the published head and the draft has been named and classified. This is the
 * only producer of `CompatibilityPlan`: it walks identity, fields, relationships, views, actions, the
 * workflow binding and the record invariants, emits one classified `CompatibilityChange` per difference,
 * and leaves the plan to put them in order. The draft is advanced to its next version before anything is
 * compared, so the status and version fields that publication itself moves never register as differences.
 * `BusinessDefinitionService` reads the resulting plan to decide whether publication needs an explicit
 * confirmation, and stores it beside the version it published.
 *
 * @since  2.0.0
 */
final class BusinessDefinitionCompatibilityAnalyzer
{
    /**
     * Compare the published head against the draft that would replace it and classify every difference.
     *
     * A null `$before` is the first publication of the handle: there is nothing to compare against, so the
     * plan carries the single additive change that records the creation itself.
     *
     * @param   ?EntityTypeDefinition  $before  Published version currently in service, or null when this
     *          handle has never been published.
     * @param   EntityTypeDefinition   $draft   Draft being assessed; it is advanced to the next version
     *          before comparison, so it must still carry draft status.
     *
     * @return  CompatibilityPlan  Both version numbers, both canonical checksums, and the classified changes.
     *
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the draft is not in draft
     *          status and therefore cannot be advanced to the next published version.
     *
     * @since   2.0.0
     */
    public function analyze(?EntityTypeDefinition $before, EntityTypeDefinition $draft): CompatibilityPlan
    {
        $next = ($before->definitionVersion ?? 0) + 1;
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

    /**
     * Record the identity, lifecycle and exposure differences between the two versions.
     *
     * Storage mode, identity strategy and scope are the three that cannot move without abandoning the rows
     * already stored, so any change to them is destructive. Turning soft deletion off is destructive too,
     * since the restore metadata goes with it, while turning it on only adds. Everything else here —
     * labels, audit policy, revision policy, compatibility metadata, the three delivery surfaces and the
     * explicit portal-operation allowlist — leaves stored records untouched and is classified as behaviour
     * changing.
     *
     * @param   EntityTypeDefinition       $before   Published version currently in service.
     * @param   EntityTypeDefinition       $after    Draft as it would be published.
     * @param   list<CompatibilityChange>  $changes  Accumulator the discovered changes are appended to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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
        if ($before->softDeleteEnabled !== $after->softDeleteEnabled) {
            $changes[] = new CompatibilityChange(
                '/soft_delete_enabled',
                $after->softDeleteEnabled
                    ? CompatibilityClassification::Additive
                    : CompatibilityClassification::Destructive,
                sprintf('%s soft deletion and restore metadata.', $after->softDeleteEnabled ? 'Enable' : 'Disable'),
            );
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
        if ($before->portalOperations() !== $after->portalOperations()) {
            $changes[] = new CompatibilityChange(
                '/exposure/portal_operations',
                CompatibilityClassification::BehaviorChanging,
                'Change the explicitly enabled portal business-record operations.',
            );
        }
    }

    /**
     * Match fields by handle and record what adding, removing or reshaping each one costs.
     *
     * Removing a field is destructive. Converting its type, forbidding nulls it used to allow, tightening
     * its persisted constraints, adding or dropping its index, and withdrawing accepted options each oblige
     * the stored column to be migrated. Making a field required is only a compatible tightening when a
     * default fills the rows already there, and a migration when nothing does; adding a field runs that
     * same test and is otherwise additive, as is every relaxation. What is left — type configuration,
     * computed, immutability and sensitivity behaviour, and the delivery and presentation metadata compared
     * as the residue of the exported document — changes behaviour without touching what is stored.
     *
     * @param   EntityTypeDefinition       $before   Published version currently in service.
     * @param   EntityTypeDefinition       $after    Draft as it would be published.
     * @param   list<CompatibilityChange>  $changes  Accumulator the discovered changes are appended to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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
                || $field->computationMode !== $candidate->computationMode
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
                'unique', 'indexed', 'computation_mode',
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

    /**
     * Read one configuration key as the list of strings it declares.
     *
     * Used to spot options a field has stopped accepting, which is the configuration change that can strand
     * values already stored. A key that is absent, does not hold a list, or holds no strings reads as an
     * empty list rather than raising, so a shape the field type does not use never blocks the comparison.
     *
     * @param   FieldDefinition  $field  Field whose type-specific configuration is being read.
     * @param   string           $key    Configuration key to read, such as `options`.
     *
     * @return  list<string>  The string entries under the key, in declaration order; empty when the key
     *          declares none.
     *
     * @since   2.0.0
     */
    private static function stringConfiguration(FieldDefinition $field, string $key): array
    {
        $value = $field->configuration[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * Match relationships by handle and record additions, removals and reshapes.
     *
     * A relationship is compared by its whole exported document rather than field by field, because
     * cardinality, ownership and delete behaviour are only meaningful together: any difference at all means
     * the stored links have to be migrated. Removal is destructive, and a new relationship is additive
     * unless it is required, which obliges every existing record to acquire one.
     *
     * @param   EntityTypeDefinition       $before   Published version currently in service.
     * @param   EntityTypeDefinition       $after    Draft as it would be published.
     * @param   list<CompatibilityChange>  $changes  Accumulator the discovered changes are appended to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Record differences in the named views and actions, the workflow binding and the record invariants.
     *
     * None of these reshape a stored record, so nothing found here is ever destructive or
     * migration-requiring: adding a view or an action is additive, while removing or editing one, rebinding
     * the workflow, or changing the cross-field invariants is behaviour changing. Views and actions are
     * matched by handle and compared by their exported documents; the workflow and the invariants are
     * compared whole.
     *
     * @param   EntityTypeDefinition       $before   Published version currently in service.
     * @param   EntityTypeDefinition       $after    Draft as it would be published.
     * @param   list<CompatibilityChange>  $changes  Accumulator the discovered changes are appended to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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
        $oldInvariants = array_map(
            static fn (RecordInvariantDefinition $invariant): array => $invariant->toArray(),
            $before->recordInvariants(),
        );
        $newInvariants = array_map(
            static fn (RecordInvariantDefinition $invariant): array => $invariant->toArray(),
            $after->recordInvariants(),
        );
        if ($oldInvariants !== $newInvariants) {
            $changes[] = new CompatibilityChange(
                '/record_invariants',
                CompatibilityClassification::BehaviorChanging,
                'Change cross-field record invariants.',
            );
        }
    }

    /**
     * Key one version's parts by their handles, so the two versions can be matched entry by entry.
     *
     * @template T of object
     *
     * @param   list<T>              $values  Fields or relationships declared by one version.
     * @param   callable(T): string  $key     Reads the handle a part is matched on.
     *
     * @return  array<string, T>  The parts keyed by handle; a repeated handle would keep only the last
     *          entry, which a definition already rejects when it is constructed.
     *
     * @since   2.0.0
     */
    private static function index(array $values, callable $key): array
    {
        $result = [];
        foreach ($values as $value) {
            $result[$key($value)] = $value;
        }
        return $result;
    }

    /**
     * Export named documents keyed by handle, so the two versions can be compared by value.
     *
     * @param   list<ActionDefinition|ViewDefinition>  $values  Views or actions declared by one version.
     *
     * @return  array<string, array<string, mixed>>  Each handle mapped to its exported document, which is
     *          what equality is then tested on.
     *
     * @since   2.0.0
     */
    private static function documents(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $result[$value->handle] = $value->toArray();
        }
        return $result;
    }
}
