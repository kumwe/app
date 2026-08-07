<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class FieldDefinition
{
    /** @var list<string> */
    public array $normalizers;

    /** @var list<array<string, mixed>> */
    public array $validators;

    /** @var list<string> */
    public array $placements;

    /**
     * @param list<string> $normalizers
     * @param list<array<string, mixed>> $validators
     * @param list<string> $placements
     */
    public function __construct(
        public string $handle,
        public string $label,
        public string $type,
        public string $description = '',
        public bool $required = false,
        public bool $nullable = true,
        public mixed $default = null,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        array $normalizers = [],
        array $validators = [],
        public bool $unique = false,
        public bool $indexed = false,
        public bool $immutableAfterCreate = false,
        public bool $serverOnly = false,
        public bool $computed = false,
        public bool $readOnly = false,
        public bool $createVisible = true,
        public bool $updateVisible = true,
        public bool $readVisible = true,
        public bool $searchable = false,
        public bool $filterable = false,
        public bool $sortable = false,
        public bool $reportable = false,
        public bool $exportable = false,
        public Sensitivity $sensitivity = Sensitivity::Internal,
        public bool $localized = false,
        public string $helpText = '',
        public string $formGroup = 'general',
        public int $order = 0,
        array $placements = ['form', 'detail'],
        public ?Expression $visibilityCondition = null,
        public ?Expression $editabilityCondition = null,
        public ?Expression $formula = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
            throw new InvalidBusinessDefinition('A business field handle is invalid.');
        }
        if ($label === '' || strlen($label) > 120 || strlen($description) > 1000 || strlen($helpText) > 1000) {
            throw new InvalidBusinessDefinition('A business field has invalid human-readable metadata.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $type) !== 1) {
            throw new InvalidBusinessDefinition('A business field type identifier is invalid.');
        }
        if ($required && $nullable) {
            throw new InvalidBusinessDefinition('A required business field cannot also be nullable.');
        }
        if ($default !== null) {
            CanonicalDefinitionJson::encode($default);
        }
        if ($length !== null && ($length < 1 || $length > 1_000_000)) {
            throw new InvalidBusinessDefinition('A business field length is outside the supported bounds.');
        }
        if (($precision === null) !== ($scale === null)) {
            throw new InvalidBusinessDefinition('Business field precision and scale must be declared together.');
        }
        if ($precision !== null && ($precision < 1 || $precision > 65 || $scale < 0 || $scale > $precision)) {
            throw new InvalidBusinessDefinition('A business field precision or scale is invalid.');
        }
        if (in_array($type, ['core.decimal', 'core.money', 'core.quantity'], true) && $precision === null) {
            throw new InvalidBusinessDefinition('Exact numeric fields require explicit precision and scale.');
        }
        if ($computed && (!$readOnly || !$serverOnly || $formula === null)) {
            throw new InvalidBusinessDefinition('A computed field must be server-only, read-only, and have a formula.');
        }
        if ($type === 'core.secret' && ($searchable || $filterable || $sortable || $reportable || $exportable)) {
            throw new InvalidBusinessDefinition('A secret field cannot be searched, filtered, sorted, reported, or exported.');
        }
        if ($type === 'core.secret' && $sensitivity !== Sensitivity::Secret) {
            throw new InvalidBusinessDefinition('An encrypted secret field requires secret sensitivity.');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $formGroup) !== 1 || $order < 0 || $order > 100_000) {
            throw new InvalidBusinessDefinition('A business field form placement is invalid.');
        }
        $this->normalizers = self::identifiers($normalizers, 'normalizer', 32);
        if (count($validators) > 32) {
            throw new InvalidBusinessDefinition('A business field has too many validators.');
        }
        foreach ($validators as $validator) {
            CanonicalDefinitionJson::encode($validator);
        }
        $this->validators = $validators;
        $allowedPlacements = ['list', 'detail', 'form', 'history', 'relation'];
        $placements = array_values(array_unique($placements));
        if ($placements === [] || array_diff($placements, $allowedPlacements) !== []) {
            throw new InvalidBusinessDefinition('A business field placement is invalid.');
        }
        sort($placements, SORT_STRING);
        $this->placements = $placements;
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        $allowed = [
            'handle', 'label', 'type', 'description', 'required', 'nullable', 'default', 'length', 'precision',
            'scale', 'normalizers', 'validators', 'unique', 'indexed', 'immutable_after_create', 'server_only',
            'computed', 'read_only', 'create_visible', 'update_visible', 'read_visible', 'searchable', 'filterable',
            'sortable', 'reportable', 'exportable', 'sensitivity', 'localized', 'help_text', 'form_group', 'order',
            'placements', 'visibility_condition', 'editability_condition', 'formula',
        ];
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidBusinessDefinition('A business field contains an unknown property.');
        }

        return new self(
            self::string($document, 'handle'),
            self::string($document, 'label'),
            self::string($document, 'type'),
            self::optionalString($document, 'description'),
            self::boolean($document, 'required'),
            self::boolean($document, 'nullable', true),
            $document['default'] ?? null,
            self::optionalInteger($document, 'length'),
            self::optionalInteger($document, 'precision'),
            self::optionalInteger($document, 'scale'),
            self::stringList($document, 'normalizers'),
            self::objectList($document, 'validators'),
            self::boolean($document, 'unique'),
            self::boolean($document, 'indexed'),
            self::boolean($document, 'immutable_after_create'),
            self::boolean($document, 'server_only'),
            self::boolean($document, 'computed'),
            self::boolean($document, 'read_only'),
            self::boolean($document, 'create_visible', true),
            self::boolean($document, 'update_visible', true),
            self::boolean($document, 'read_visible', true),
            self::boolean($document, 'searchable'),
            self::boolean($document, 'filterable'),
            self::boolean($document, 'sortable'),
            self::boolean($document, 'reportable'),
            self::boolean($document, 'exportable'),
            Sensitivity::tryFrom(self::optionalString($document, 'sensitivity', 'internal'))
                ?? throw new InvalidBusinessDefinition('A business field sensitivity is invalid.'),
            self::boolean($document, 'localized'),
            self::optionalString($document, 'help_text'),
            self::optionalString($document, 'form_group', 'general'),
            self::integer($document, 'order'),
            self::stringList($document, 'placements', ['form', 'detail']),
            self::expression($document, 'visibility_condition'),
            self::expression($document, 'editability_condition'),
            self::expression($document, 'formula'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'label' => $this->label,
            'type' => $this->type,
            'description' => $this->description,
            'required' => $this->required,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'length' => $this->length,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'normalizers' => $this->normalizers,
            'validators' => $this->validators,
            'unique' => $this->unique,
            'indexed' => $this->indexed,
            'immutable_after_create' => $this->immutableAfterCreate,
            'server_only' => $this->serverOnly,
            'computed' => $this->computed,
            'read_only' => $this->readOnly,
            'create_visible' => $this->createVisible,
            'update_visible' => $this->updateVisible,
            'read_visible' => $this->readVisible,
            'searchable' => $this->searchable,
            'filterable' => $this->filterable,
            'sortable' => $this->sortable,
            'reportable' => $this->reportable,
            'exportable' => $this->exportable,
            'sensitivity' => $this->sensitivity->value,
            'localized' => $this->localized,
            'help_text' => $this->helpText,
            'form_group' => $this->formGroup,
            'order' => $this->order,
            'placements' => $this->placements,
            'visibility_condition' => $this->visibilityCondition?->toArray(),
            'editability_condition' => $this->editabilityCondition?->toArray(),
            'formula' => $this->formula?->toArray(),
        ];
    }

    /** @param array<string, mixed> $document */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $document */
    private static function optionalString(array $document, string $key, string $default = ''): string
    {
        $value = $document[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be a string.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $document */
    private static function boolean(array $document, string $key, bool $default = false): bool
    {
        $value = $document[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be boolean.');
        }
        return $value;
    }

    /** @param array<string, mixed> $document */
    private static function integer(array $document, string $key): int
    {
        $value = $document[$key] ?? 0;
        if (!is_int($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be an integer.');
        }
        return $value;
    }

    /** @param array<string, mixed> $document */
    private static function optionalInteger(array $document, string $key): ?int
    {
        $value = $document[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be an integer or null.');
        }
        return $value;
    }

    /** @param array<string, mixed> $document @param list<string> $default @return list<string> */
    private static function stringList(array $document, string $key, array $default = []): array
    {
        $value = $document[$key] ?? $default;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidBusinessDefinition('Business field property ' . $key . ' must contain strings.');
            }
            $result[] = $item;
        }
        return $result;
    }

    /** @param array<string, mixed> $document @return list<array<string, mixed>> */
    private static function objectList(array $document, string $key): array
    {
        $value = $document[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidBusinessDefinition('Business field property ' . $key . ' must contain objects.');
            }
            /** @var array<string, mixed> $item */
            $result[] = $item;
        }
        return $result;
    }

    /** @param array<string, mixed> $document */
    private static function expression(array $document, string $key): ?Expression
    {
        $value = $document[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business field expression ' . $key . ' must be an object.');
        }
        /** @var array<string, mixed> $value */
        return Expression::fromArray($value);
    }

    /** @param list<string> $values @return list<string> */
    private static function identifiers(array $values, string $kind, int $maximum): array
    {
        if (count($values) > $maximum || count($values) !== count(array_unique($values))) {
            throw new InvalidBusinessDefinition('Business field ' . $kind . ' entries are duplicated or unbounded.');
        }
        foreach ($values as $value) {
            if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $value) !== 1) {
                throw new InvalidBusinessDefinition('A business field ' . $kind . ' identifier is invalid.');
            }
        }
        return $values;
    }
}
