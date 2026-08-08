<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use DateTimeImmutable;
use DateTimeZone;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\Sensitivity;
use Ramsey\Uuid\Uuid;
use Throwable;

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
            $this->validateIdentity($definition);
            foreach ($definition->fields() as $field) {
                if ($field->handle === 'runtime_relation_evidence') {
                    throw new InvalidBusinessDefinition(
                        'A business field handle is reserved for immutable runtime revision evidence.',
                    );
                }
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
                if ($relationship->required) {
                    throw new InvalidBusinessDefinition(
                        'Required relationships need atomic create inputs and are not publishable by this runtime.',
                    );
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
                    if (
                        $inverse[0]->inverse !== $relationship->handle
                        || !$this->inverseKindsMatch($relationship, $inverse[0])
                    ) {
                        throw new InvalidBusinessDefinition(
                            'Business relationship inverses must be reciprocal and cardinality-compatible.',
                        );
                    }
                }
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $ownershipEdges[$definition->handle][] = $target->handle;
                }
                if (
                    $relationship->onDelete === DeleteBehavior::Cascade
                    && $relationship->kind !== RelationshipKind::OwnedLineCollection
                ) {
                    throw new InvalidBusinessDefinition(
                        'Only an owned line collection can use automatic cascade deletion.',
                    );
                }
                if (
                    $relationship->onDelete === DeleteBehavior::SetNull
                    && !in_array($relationship->kind, [
                        RelationshipKind::OneToOne,
                        RelationshipKind::ManyToOne,
                    ], true)
                ) {
                    throw new InvalidBusinessDefinition(
                        'Set-null deletion requires a singular relationship with explicit runtime revision handling.',
                    );
                }
            }
        }
        $this->assertAcyclicOwnership($ownershipEdges);
    }

    private function validateIdentity(EntityTypeDefinition $definition): void
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        $identity = array_values(array_filter(
            $definition->fields(),
            static fn (FieldDefinition $field): bool => $field->type === $type,
        ))[0] ?? null;
        if (
            !$identity instanceof FieldDefinition
            || !$identity->required || $identity->nullable || !$identity->unique
            || !$identity->immutableAfterCreate
        ) {
            throw new InvalidBusinessDefinition(
                'A business identity field must be required, non-null, unique, and immutable after creation.',
            );
        }
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

    private function inverseKindsMatch(
        RelationshipDefinition $relationship,
        RelationshipDefinition $inverse,
    ): bool {
        if (
            $relationship->kind === RelationshipKind::OwnedLineCollection
            || $inverse->kind === RelationshipKind::OwnedLineCollection
        ) {
            return false;
        }
        $expected = match ($relationship->kind) {
            RelationshipKind::OneToOne => RelationshipKind::OneToOne,
            RelationshipKind::ManyToOne => RelationshipKind::OneToMany,
            RelationshipKind::OneToMany => RelationshipKind::ManyToOne,
            RelationshipKind::ManyToMany => RelationshipKind::ManyToMany,
            RelationshipKind::OwnedLineCollection => null,
        };

        return $inverse->kind === $expected
            && ($relationship->kind !== RelationshipKind::ManyToMany
                || $relationship->ordered === $inverse->ordered);
    }

    private function validateFieldRules(FieldDefinition $field): void
    {
        $this->validatePortableLength($field);
        $this->validatePortableSort($field);
        if ($field->type === 'core.secret' && $field->default !== null) {
            throw new InvalidBusinessDefinition(
                'An encrypted secret field cannot declare a reusable plaintext default.',
            );
        }
        if (!$field->required && !$field->nullable && $field->default === null && !$field->computed) {
            throw new InvalidBusinessDefinition(
                'An optional non-null business field requires a non-null default or stored computation.',
            );
        }
        if (
            $field->type === 'core.ordered_lines'
            && (
                $field->required || !$field->nullable || $field->default !== null
                || $field->unique || $field->indexed || $field->computed
                || $field->searchable || $field->filterable || $field->sortable || $field->reportable
            )
        ) {
            throw new InvalidBusinessDefinition(
                'An ordered-line field is an optional owned collection and cannot use scalar storage rules.',
            );
        }
        if (
            $field->computed && $field->computationMode === ComputationMode::Virtual
            && ($field->unique || $field->indexed || $field->searchable || $field->filterable
                || $field->sortable || $field->reportable)
        ) {
            throw new InvalidBusinessDefinition(
                'A virtual computed field cannot declare physical query, index, or uniqueness capabilities.',
            );
        }
        if ($field->type === 'core.enum') {
            $options = $field->configuration['options'] ?? null;
            if (
                !is_array($options) || $options === []
                || count(array_unique($options, SORT_STRING)) !== count($options)
            ) {
                throw new InvalidBusinessDefinition('An enum field requires distinct declared options.');
            }
            $maximum = $field->length ?? 191;
            foreach ($options as $option) {
                if (!is_string($option) || mb_strlen($option, 'UTF-8') > $maximum) {
                    throw new InvalidBusinessDefinition('An enum option exceeds the field storage length.');
                }
            }
            if (
                $field->default !== null
                && (!is_string($field->default) || !in_array($field->default, $options, true))
            ) {
                throw new InvalidBusinessDefinition('An enum field default must be one of its declared options.');
            }
        }
        $this->validateDefault($field);
        $normalizers = [
            'trim', 'lowercase', 'uppercase', 'unicode_nfc', 'email', 'url', 'phone', 'decimal_scale',
        ];
        if (array_diff($field->normalizers, $normalizers) !== []) {
            throw new InvalidBusinessDefinition('Business field ' . $field->handle . ' uses an unknown normalizer.');
        }
        foreach ($field->normalizers as $normalizer) {
            $compatible = $normalizer === 'decimal_scale'
                ? $this->runtimeDecimal($field)
                : $this->normalizerStringInput($field);
            if (!$compatible) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Business field %s normalizer %s is incompatible with its value type.',
                    $field->handle,
                    $normalizer,
                ));
            }
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
            if (!$this->validatorCompatible($field, $rule)) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Business field %s validator %s is incompatible with its value type.',
                    $field->handle,
                    $rule,
                ));
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
                if (
                    !is_string($value) || $value === '' || strlen($value) > 512
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

    private function normalizerStringInput(FieldDefinition $field): bool
    {
        if ($field->type === 'core.computed') {
            return $field->formula?->type === 'string';
        }
        if ($field->type === 'core.secret') {
            return true;
        }
        if (
            in_array($field->type, [
            'core.decimal',
            'core.date',
            'core.local_time',
            'core.instant',
            'core.zoned_datetime',
            ], true)
        ) {
            return false;
        }

        return in_array($this->fieldTypes->get($field->type)->valueType, ['string', 'reference'], true);
    }

    private function validatorCompatible(FieldDefinition $field, string $rule): bool
    {
        $string = $this->runtimeString($field);
        $integer = $this->runtimeInteger($field);
        $decimal = $this->runtimeDecimal($field);

        return match ($rule) {
            'pattern', 'min_length', 'max_length', 'email', 'url', 'uuid' => $string,
            'min', 'max' => $integer || $decimal,
            'one_of' => $string || $integer || $this->runtimeBoolean($field),
            'integer' => $integer,
            'decimal' => $decimal,
            default => false,
        };
    }

    private function runtimeString(FieldDefinition $field): bool
    {
        if ($field->type === 'core.computed') {
            return $field->formula?->type === 'string';
        }
        if (
            in_array($field->type, [
            'core.decimal',
            'core.date',
            'core.local_time',
            'core.instant',
            'core.zoned_datetime',
            'core.secret',
            ], true)
        ) {
            return false;
        }

        return in_array($this->fieldTypes->get($field->type)->valueType, ['string', 'reference'], true);
    }

    private function runtimeInteger(FieldDefinition $field): bool
    {
        return $field->type === 'core.computed'
            ? $field->formula?->type === 'integer'
            : $this->fieldTypes->get($field->type)->valueType === 'integer';
    }

    private function runtimeBoolean(FieldDefinition $field): bool
    {
        return $field->type === 'core.computed'
            ? $field->formula?->type === 'boolean'
            : $this->fieldTypes->get($field->type)->valueType === 'boolean';
    }

    private function runtimeDecimal(FieldDefinition $field): bool
    {
        return $field->type === 'core.decimal'
            || ($field->type === 'core.computed' && $field->formula?->type === 'decimal');
    }

    private function validatePortableLength(FieldDefinition $field): void
    {
        if ($field->length === null) {
            return;
        }
        $maximum = match ($field->type) {
            'core.reference_identity', 'core.entity_reference', 'core.enum', 'core.phone' => 191,
            'core.email' => 320,
            'core.text' => 1000,
            'core.computed' => $field->formula?->type === 'string' ? 1000 : null,
            default => !str_starts_with($field->type, 'core.')
                && $this->fieldTypes->get($field->type)->storageType === 'string'
                    ? 1000
                    : null,
        };
        if ($maximum !== null && $field->length > $maximum) {
            throw new InvalidBusinessDefinition(sprintf(
                'Business field %s length exceeds its portable physical storage limit of %d.',
                $field->handle,
                $maximum,
            ));
        }
    }

    private function validatePortableSort(FieldDefinition $field): void
    {
        if (!$field->sortable) {
            return;
        }
        if (
            !$field->readVisible
            || in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)
        ) {
            throw new InvalidBusinessDefinition(
                'A sortable business field must be read-visible and cannot contain redacted values.',
            );
        }
        $nonPortableCore = [
            'core.bounded_json',
            'core.embedded_value',
            'core.money',
            'core.ordered_lines',
            'core.quantity',
            'core.rich_text',
            'core.secret',
            'core.url',
            'core.zoned_datetime',
        ];
        $fieldType = $this->fieldTypes->get($field->type);
        if (
            in_array($field->type, $nonPortableCore, true)
            || (!str_starts_with($field->type, 'core.') && in_array($fieldType->storageType, ['json', 'text'], true))
        ) {
            throw new InvalidBusinessDefinition(
                'A sortable business field requires bounded scalar storage with portable keyset semantics.',
            );
        }
        $stringLength = match ($field->type) {
            'core.email' => $field->length ?? 320,
            'core.enum', 'core.phone', 'core.reference_identity', 'core.text' => $field->length ?? 191,
            'core.computed' => $field->formula?->type === 'string' ? ($field->length ?? 191) : null,
            default => !str_starts_with($field->type, 'core.') && $fieldType->storageType === 'string'
                ? ($field->length ?? 191)
                : null,
        };
        if ($stringLength !== null && $stringLength > 512) {
            throw new InvalidBusinessDefinition(
                'A sortable string field cannot exceed the 512-character stateless cursor bound.',
            );
        }
    }

    private function validateDefault(FieldDefinition $field): void
    {
        $value = $field->default;
        if ($value === null) {
            return;
        }
        $valid = match ($field->type) {
            'core.boolean' => is_bool($value),
            'core.integer' => is_int($value) && $value >= -2_147_483_648 && $value <= 2_147_483_647,
            'core.decimal' => $this->exactDefault($value, $field),
            'core.money' => $this->moneyDefault($value, $field),
            'core.quantity' => $this->quantityDefault($value, $field),
            'core.date' => $this->dateDefault($value),
            'core.local_time' => $this->timeDefault($value),
            'core.instant' => $this->instantDefault($value),
            'core.zoned_datetime' => $this->zonedDefault($value),
            'core.uuid', 'core.media_reference' => is_string($value) && Uuid::isValid($value),
            'core.reference_identity', 'core.entity_reference' => $this->referenceDefault($value, $field),
            'core.text' => $this->stringDefault($value, $field->length ?? 191),
            'core.rich_text' => $this->stringDefault($value, $field->length ?? 1_000_000),
            'core.email' => $this->emailDefault($value, $field),
            'core.url' => $this->urlDefault($value, $field),
            'core.phone' => $this->phoneDefault($value, $field),
            'core.enum' => is_string($value)
                && in_array($value, is_array($field->configuration['options'] ?? null)
                    ? $field->configuration['options'] : [], true),
            'core.embedded_value', 'core.bounded_json' => $this->jsonDefault($value, $field),
            'core.computed', 'core.secret', 'core.ordered_lines' => false,
            default => $this->customDefault($value, $field),
        };
        if (!$valid) {
            throw new InvalidBusinessDefinition(sprintf(
                'Business field %s has an invalid default for %s.',
                $field->handle,
                $field->type,
            ));
        }
    }

    private function exactDefault(mixed $value, FieldDefinition $field): bool
    {
        if ((!is_int($value) && !is_string($value)) || $field->precision === null || $field->scale === null) {
            return false;
        }
        $value = (string) $value;
        if (preg_match('/^-?(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $matches) !== 1) {
            return false;
        }
        $integerDigits = $matches[1] === '0' ? 0 : strlen($matches[1]);
        $fractionDigits = strlen($matches[2] ?? '');

        return $fractionDigits <= $field->scale && $integerDigits <= $field->precision - $field->scale;
    }

    private function customDefault(mixed $value, FieldDefinition $field): bool
    {
        $fieldType = $this->fieldTypes->get($field->type);

        return match ($fieldType->storageType) {
            'guid' => is_string($value) && Uuid::isValid($value),
            'string' => $this->stringDefault($value, min($field->length ?? 191, 1000)),
            'text' => $this->stringDefault($value, $field->length ?? 1_000_000),
            'integer' => is_int($value) && $value >= -2_147_483_648 && $value <= 2_147_483_647,
            'boolean' => is_bool($value),
            'date' => $this->dateDefault($value),
            'time' => $this->timeDefault($value),
            'datetime' => $this->instantDefault($value),
            'json' => $this->customJsonDefault($value, $field, $fieldType->valueType),
            default => false,
        };
    }

    private function customJsonDefault(mixed $value, FieldDefinition $field, string $valueType): bool
    {
        $shape = match ($valueType) {
            'string', 'reference' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && !array_is_list($value),
            'collection' => is_array($value) && array_is_list($value),
            default => false,
        };

        return $shape && $this->jsonDefault($value, $field);
    }

    private function moneyDefault(mixed $value, FieldDefinition $field): bool
    {
        if (!$this->compositeDefault($value, ['amount', 'currency'])) {
            return false;
        }
        $currency = $value['currency'];
        $configured = $field->configuration['currency'] ?? null;

        return $this->exactDefault($value['amount'], $field)
            && is_string($currency)
            && preg_match('/^[A-Z]{3}$/D', $currency) === 1
            && (!is_string($configured) || hash_equals($configured, $currency));
    }

    private function quantityDefault(mixed $value, FieldDefinition $field): bool
    {
        if (!$this->compositeDefault($value, ['amount', 'unit'])) {
            return false;
        }
        $unit = $value['unit'];
        $configured = $field->configuration['unit'] ?? null;

        return $this->exactDefault($value['amount'], $field)
            && is_string($unit)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,62}$/D', $unit) === 1
            && (!is_string($configured) || hash_equals($configured, $unit));
    }

    /** @param list<string> $keys */
    private function compositeDefault(mixed $value, array $keys): bool
    {
        return is_array($value) && !array_is_list($value)
            && count($value) === count($keys)
            && array_diff(array_keys($value), $keys) === []
            && array_diff($keys, array_keys($value)) === [];
    }

    private function dateDefault(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === $value
            && (int) substr($value, 0, 4) >= 1000;
    }

    private function timeDefault(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{6})?$/D', $value) === 1;
    }

    private function instantDefault(mixed $value): bool
    {
        if (
            !is_string($value) || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                . '(?:\.[0-9]{1,6})?(?:Z|\+00:00)$/D',
                $value,
            ) !== 1
        ) {
            return false;
        }
        try {
            $instant = new DateTimeImmutable($value);
        } catch (Throwable) {
            return false;
        }
        $errors = DateTimeImmutable::getLastErrors();

        return ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $instant->getOffset() === 0
            && (int) substr($value, 0, 4) >= 1000;
    }

    private function zonedDefault(mixed $value): bool
    {
        return $this->compositeDefault($value, ['instant', 'timezone'])
            && $this->instantDefault($value['instant'])
            && is_string($value['timezone'])
            && in_array($value['timezone'], DateTimeZone::listIdentifiers(), true);
    }

    private function referenceDefault(mixed $value, FieldDefinition $field): bool
    {
        return $this->stringDefault($value, min($field->length ?? 191, 191))
            && $value !== ''
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function stringDefault(mixed $value, int $maximum): bool
    {
        return is_string($value) && mb_strlen($value, 'UTF-8') <= $maximum;
    }

    private function emailDefault(mixed $value, FieldDefinition $field): bool
    {
        return $this->stringDefault($value, $field->length ?? 320)
            && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function urlDefault(mixed $value, FieldDefinition $field): bool
    {
        if (
            !$this->stringDefault($value, $field->length ?? 4096)
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function phoneDefault(mixed $value, FieldDefinition $field): bool
    {
        return $this->stringDefault($value, $field->length ?? 64)
            && preg_match('/^\+?[0-9][0-9 x#*]{2,62}$/D', $value) === 1;
    }

    private function jsonDefault(mixed $value, FieldDefinition $field): bool
    {
        $maximum = $field->configuration['max_bytes'] ?? 65_536;
        if (!is_int($maximum)) {
            return false;
        }

        return strlen(\Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson::encode($value)) <= $maximum;
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
        if (
            $unit !== null
            && (!is_string($unit) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,31}$/D', $unit) !== 1)
        ) {
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
        if ($maxBytes !== null && (!is_int($maxBytes) || $maxBytes < 2 || $maxBytes > 1_000_000)) {
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
