<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\CMS\BusinessRecord\Domain\EncryptedEnvelope;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\MoneyValue;
use Kumwe\CMS\BusinessRecord\Domain\QuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\CMS\BusinessRecord\Domain\ZonedDateTimeValue;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use Normalizer;
use Ramsey\Uuid\Uuid;
use Throwable;

final readonly class RecordValueCodec
{
    private FieldTypeRegistry $fieldTypes;

    public function __construct(private SecretCipher $secrets, ?FieldTypeRegistry $fieldTypes = null)
    {
        $this->fieldTypes = $fieldTypes ?? new FieldTypeRegistry();
    }

    public function identity(
        EntityTypeDefinition $definition,
        array $input,
        ?string $requestedRecordId,
    ): string {
        $field = $this->identityField($definition);
        $provided = $input[$field->handle] ?? null;
        if ($provided !== null && !is_string($provided)) {
            throw new InvalidArgumentException('A business-record identity must be a string.');
        }
        if ($field->type === 'core.uuid') {
            $normalizedProvided = is_string($provided) ? strtolower($provided) : null;
            $normalizedRequested = $requestedRecordId !== null ? strtolower($requestedRecordId) : null;
            $identity = $normalizedRequested ?? $normalizedProvided ?? Uuid::uuid7()->toString();
            if (!Uuid::isValid($identity)) {
                throw new InvalidArgumentException('A UUID business-record identity is invalid.');
            }
        } else {
            $normalizedProvided = is_string($provided) ? $this->normalizeString($provided, $field) : null;
            $normalizedRequested = $requestedRecordId !== null
                ? $this->normalizeString($requestedRecordId, $field)
                : null;
            $identity = $normalizedRequested ?? $normalizedProvided;
            if (!is_string($identity) || $identity === '') {
                throw new InvalidArgumentException('A reference-identity record requires an explicit identity.');
            }
            if (strlen($identity) > ($field->length ?? 191)) {
                throw new InvalidArgumentException('A reference identity exceeds its configured length.');
            }
        }
        if (
            isset($normalizedProvided, $normalizedRequested)
            && !hash_equals($normalizedRequested, $normalizedProvided)
        ) {
            throw new InvalidArgumentException('The record ID and declared identity field disagree.');
        }

        return $identity;
    }

    public function normalize(
        FieldDefinition $field,
        mixed $value,
        string $siteIdentifier,
        string $definitionId,
        string $recordId,
    ): mixed {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            throw new InvalidArgumentException('Business-record values cannot use PHP floats.');
        }
        $value = $this->applyNormalizers($value, $field);

        return match ($field->type) {
            'core.uuid', 'core.media_reference' => $this->uuid($value),
            'core.entity_reference' => $this->referenceIdentity($value, $field),
            'core.reference_identity', 'core.text', 'core.rich_text' =>
                $this->boundedString($value, $field->length ?? ($field->type === 'core.rich_text' ? 1_000_000 : 191)),
            'core.computed' => $this->computed($value, $field),
            'core.integer' => $this->integer($value),
            'core.decimal' => $this->decimal($value, $field),
            'core.money' => $this->money($value, $field),
            'core.quantity' => $this->quantity($value, $field),
            'core.boolean' => $this->boolean($value),
            'core.enum' => $this->enumeration($value, $field),
            'core.date' => $this->date($value),
            'core.local_time' => $this->time($value),
            'core.instant' => $this->instant($value),
            'core.zoned_datetime' => $this->zonedDateTime($value),
            'core.email' => $this->email($value, $field),
            'core.url' => $this->url($value, $field),
            'core.phone' => $this->phone($value, $field),
            'core.embedded_value', 'core.bounded_json' => $this->boundedJson($value, $field),
            'core.secret' => $this->secret($value, $siteIdentifier, $definitionId, $recordId, $field->handle),
            'core.ordered_lines' => throw new InvalidArgumentException(
                'Ordered lines must be changed through relationship and reorder commands.',
            ),
            default => $this->custom($value, $field),
        };
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed> Physical column names to DBAL values.
     */
    public function encodeColumns(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        array $values,
    ): array {
        $encoded = [];
        foreach ($definition->fields() as $field) {
            if (
                !array_key_exists($field->handle, $values)
                || ($field === $this->identityField($definition)
                    && $definition->identityStrategy === IdentityStrategy::Uuid)
                || ($field->formula !== null && $field->computationMode === ComputationMode::Virtual)
            ) {
                continue;
            }
            $value = $values[$field->handle];
            $columns = $this->columns($table, $field);
            if ($columns === []) {
                continue;
            }
            foreach ($this->storageValues($field, $value) as $logical => $storageValue) {
                $column = $table->column($logical);
                if ($column !== null) {
                    $encoded[$column->physicalName] = $storageValue;
                }
            }
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function decodeColumns(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        array $row,
        string $siteIdentifier,
        string $recordKey,
    ): array {
        $values = [];
        $identity = $this->identityField($definition);
        if ($definition->identityStrategy === IdentityStrategy::Uuid) {
            $values[$identity->handle] = $recordKey;
        }
        foreach ($definition->fields() as $field) {
            if (
                ($field === $identity && $definition->identityStrategy === IdentityStrategy::Uuid)
                || $field->type === 'core.ordered_lines'
                || ($field->formula !== null && $field->computationMode === ComputationMode::Virtual)
            ) {
                continue;
            }
            $columns = $this->columns($table, $field);
            if ($columns === []) {
                continue;
            }
            $present = false;
            foreach ($columns as $column) {
                $present = $present || array_key_exists($column->physicalName, $row);
            }
            if (!$present) {
                continue;
            }
            $storage = [];
            $allNull = true;
            foreach ($columns as $column) {
                $item = $this->decodePhysical($column, $row[$column->physicalName] ?? null);
                $storage[$column->logicalName] = $item;
                $allNull = $allNull && $item === null;
            }
            $values[$field->handle] = $allNull
                ? null
                : $this->fromStorage($field, $storage, $siteIdentifier, $definition->id, $recordKey);
        }

        return $values;
    }

    public function cursorValue(PhysicalColumnBlueprint $column, mixed $value): mixed
    {
        $decoded = $this->decodePhysical($column, $value);
        if ($decoded === null) {
            return null;
        }

        return match ($column->doctrineType) {
            'boolean', 'integer', 'smallint' => $decoded,
            'bigint' => $this->cursorBigint($decoded),
            'decimal' => $this->cursorDecimal($decoded),
            'ascii_string', 'guid', 'string', 'text' => is_string($decoded)
                ? $decoded
                : throw new InvalidArgumentException('A stored textual cursor value is invalid.'),
            'date_immutable' => $this->cursorDateTime($decoded)->format('Y-m-d'),
            'time_immutable' => $this->cursorDateTime($decoded)->format('H:i:s.u'),
            'datetime_immutable', 'datetimetz_immutable' => $this->cursorDateTime($decoded)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            default => throw new InvalidArgumentException(
                'A binary or structured physical field cannot be used in a keyset cursor.',
            ),
        };
    }

    public function cursorStorageValue(PhysicalColumnBlueprint $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column->doctrineType) {
            'boolean' => $this->boolean($value),
            'integer', 'smallint' => $this->integer($value),
            'bigint' => $this->cursorBigint($value),
            'decimal' => $this->cursorDecimal($value),
            'ascii_string', 'string', 'text' => is_string($value)
                ? $value
                : throw new InvalidArgumentException('A textual cursor value is invalid.'),
            'guid' => $this->uuid($value),
            'date_immutable' => $this->date($value),
            'time_immutable' => $this->time($value),
            'datetime_immutable', 'datetimetz_immutable' => $this->instant($value),
            default => throw new InvalidArgumentException(
                'A binary or structured physical field cannot be restored from a keyset cursor.',
            ),
        };
    }

    /** @param array<string, mixed> $values */
    public function publicIdentity(
        EntityTypeDefinition $definition,
        string $recordKey,
        array $values,
    ): string {
        if ($definition->identityStrategy === IdentityStrategy::Uuid) {
            return $recordKey;
        }
        $identity = $this->identityField($definition);
        $value = $values[$identity->handle] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('A decoded record has no public reference identity.');
        }

        return $value;
    }

    private function normalizeString(string $value, FieldDefinition $field): string
    {
        $normalized = $this->applyNormalizers($value, $field);
        return is_string($normalized)
            ? $normalized
            : throw new InvalidArgumentException('String normalization produced a non-string value.');
    }

    private function applyNormalizers(mixed $value, FieldDefinition $field): mixed
    {
        foreach ($field->normalizers as $normalizer) {
            if ($normalizer === 'decimal_scale') {
                if (!is_string($value) && !is_int($value) && !$value instanceof ExactDecimal) {
                    throw new InvalidArgumentException('Decimal-scale normalization requires an exact value.');
                }
                continue;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('A text normalizer requires a string value.');
            }
            $value = match ($normalizer) {
                'trim' => trim($value),
                'lowercase' => mb_strtolower($value, 'UTF-8'),
                'uppercase' => mb_strtoupper($value, 'UTF-8'),
                'unicode_nfc' => $this->unicodeNfc($value),
                'email' => mb_strtolower(trim($value), 'UTF-8'),
                'url' => trim($value),
                'phone' => preg_replace('/[\s().-]+/u', '', trim($value))
                    ?? throw new InvalidArgumentException('Phone normalization failed.'),
                default => throw new InvalidArgumentException(
                    'An unregistered business-field normalizer was requested.',
                ),
            };
        }

        return $value;
    }

    private function unicodeNfc(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (!is_string($normalized)) {
            throw new InvalidArgumentException('Unicode normalization failed.');
        }

        return $normalized;
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || !Uuid::isValid($value)) {
            throw new InvalidArgumentException('A business-record reference must be a canonical UUID.');
        }

        return strtolower($value);
    }

    private function boundedString(mixed $value, int $limit): string
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $limit) {
            throw new InvalidArgumentException('A business-record string value has an invalid type or length.');
        }

        return $value;
    }

    private function referenceIdentity(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, min($field->length ?? 191, 191));
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('An entity-reference identity is empty or contains controls.');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) || $value < -2_147_483_648 || $value > 2_147_483_647) {
            throw new InvalidArgumentException('An integer field requires a portable signed 32-bit PHP integer.');
        }

        return $value;
    }

    private function decimal(mixed $value, FieldDefinition $field): ExactDecimal
    {
        if ($value instanceof ExactDecimal) {
            if ($value->precision !== $field->precision || $value->scale !== $field->scale) {
                throw new InvalidArgumentException('An exact decimal uses a different field precision or scale.');
            }
            return $value;
        }
        if (is_int($value)) {
            return ExactDecimal::fromInt($value, $this->precision($field), $this->scale($field));
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('An exact decimal requires a string or integer, never a float.');
        }

        return ExactDecimal::fromString($value, $this->precision($field), $this->scale($field));
    }

    private function money(mixed $value, FieldDefinition $field): MoneyValue
    {
        if ($value instanceof MoneyValue) {
            return $value;
        }
        if (
            !is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), ['amount', 'currency']) !== []
            || array_diff(['amount', 'currency'], array_keys($value)) !== []
        ) {
            throw new InvalidArgumentException('A money field requires exact amount and currency properties.');
        }
        $currency = $value['currency'];
        if (!is_string($currency)) {
            throw new InvalidArgumentException('A money currency must be a string.');
        }
        $money = new MoneyValue($this->decimal($value['amount'], $field), strtoupper($currency));
        $configured = $field->configuration['currency'] ?? null;
        if (is_string($configured) && !hash_equals(strtoupper($configured), $money->currency)) {
            throw new InvalidArgumentException('A money currency differs from the field currency.');
        }

        return $money;
    }

    private function quantity(mixed $value, FieldDefinition $field): QuantityValue
    {
        if ($value instanceof QuantityValue) {
            return $value;
        }
        if (
            !is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), ['amount', 'unit']) !== []
            || array_diff(['amount', 'unit'], array_keys($value)) !== []
        ) {
            throw new InvalidArgumentException('A quantity field requires exact amount and unit properties.');
        }
        if (!is_string($value['unit'])) {
            throw new InvalidArgumentException('A quantity unit must be a string.');
        }
        $quantity = new QuantityValue($this->decimal($value['amount'], $field), $value['unit']);
        $configured = $field->configuration['unit'] ?? null;
        if (is_string($configured) && !hash_equals($configured, $quantity->unit)) {
            throw new InvalidArgumentException('A quantity unit differs from the field unit.');
        }

        return $quantity;
    }

    private function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('A boolean field requires a PHP boolean.');
        }

        return $value;
    }

    private function enumeration(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, $field->length ?? 191);
        $options = $field->configuration['options'] ?? [];
        if (!is_array($options) || !in_array($value, $options, true)) {
            throw new InvalidArgumentException('An enum value is outside its declared options.');
        }

        return $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable && $value->format('H:i:s.u') === '00:00:00.000000') {
            if ($this->portableYear($value)) {
                return $value;
            }
            throw new InvalidArgumentException('A date field is outside the portable database year range.');
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('A date field requires YYYY-MM-DD.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value || !$this->portableYear($date)) {
            throw new InvalidArgumentException('A date field requires a valid portable canonical YYYY-MM-DD value.');
        }

        return $date;
    }

    private function time(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('A local-time field requires a canonical time string.');
        }
        $format = str_contains($value, '.') ? '!H:i:s.u' : '!H:i:s';
        $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable || $time->format(ltrim($format, '!')) !== $value) {
            throw new InvalidArgumentException('A local-time field requires HH:MM:SS with optional microseconds.');
        }

        return $time;
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            if (!$this->portableYear($value)) {
                throw new InvalidArgumentException('An instant field is outside the portable database year range.');
            }

            return $value->setTimezone(new DateTimeZone('UTC'));
        }
        if (
            !is_string($value) || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                . '(?:\.[0-9]{1,6})?(?:Z|\+00:00)$/D',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException('An instant field requires an RFC 3339 UTC string.');
        }
        try {
            $instant = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('An instant field is invalid.', 0, $exception);
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $instant->format('P') !== '+00:00' || !$this->portableYear($instant)
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        ) {
            throw new InvalidArgumentException('An instant field must use valid portable UTC time.');
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    private function portableYear(DateTimeImmutable $value): bool
    {
        $year = (int) $value->format('Y');

        return $year >= 1000 && $year <= 9999;
    }

    private function zonedDateTime(mixed $value): ZonedDateTimeValue
    {
        if ($value instanceof ZonedDateTimeValue) {
            return $value;
        }
        if (
            !is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), ['instant', 'timezone']) !== []
            || array_diff(['instant', 'timezone'], array_keys($value)) !== []
        ) {
            throw new InvalidArgumentException('A zoned date-time requires instant and timezone properties.');
        }
        if (!is_string($value['instant']) || !is_string($value['timezone'])) {
            throw new InvalidArgumentException('A zoned date-time instant and timezone must be strings.');
        }

        return ZonedDateTimeValue::fromStrings($value['instant'], $value['timezone']);
    }

    private function email(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, $field->length ?? 320);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('An email field requires a valid address.');
        }

        return $value;
    }

    private function url(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, $field->length ?? 4096);
        $parts = parse_url($value);
        if (
            filter_var($value, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('A URL field requires an absolute HTTP or HTTPS URL.');
        }

        return $value;
    }

    private function phone(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, $field->length ?? 64);
        if (preg_match('/^\+?[0-9][0-9 x#*]{2,62}$/D', $value) !== 1) {
            throw new InvalidArgumentException('A phone field has an invalid portable shape.');
        }

        return $value;
    }

    private function boundedJson(mixed $value, FieldDefinition $field): mixed
    {
        RecordValueGuard::assertValue($value);
        $canonical = RecordValueGuard::canonical($value);
        try {
            $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A bounded JSON value cannot be encoded.', 0, $exception);
        }
        $maximum = $field->configuration['max_bytes'] ?? 65_536;
        if (!is_int($maximum) || $maximum < 2 || $maximum > 1_000_000 || strlen($json) > $maximum) {
            throw new InvalidArgumentException('A bounded JSON value exceeds its configured byte limit.');
        }

        return $canonical;
    }

    private function secret(
        mixed $value,
        string $siteIdentifier,
        string $definitionId,
        string $recordId,
        string $field,
    ): EncryptedEnvelope {
        if ($value instanceof EncryptedEnvelope) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('A secret field requires a string.');
        }

        return $this->secrets->encrypt(
            $value,
            SecretAssociatedData::for($siteIdentifier, $definitionId, $recordId, $field),
        );
    }

    private function custom(mixed $value, FieldDefinition $field): mixed
    {
        $type = $this->fieldTypes->get($field->type);

        return match ($type->storageType) {
            'guid' => $this->uuid($value),
            'string' => $this->boundedString($value, min($field->length ?? 191, 1000)),
            'text' => $this->boundedString($value, $field->length ?? 1_000_000),
            'integer' => $this->integer($value),
            'boolean' => $this->boolean($value),
            'date' => $this->date($value),
            'time' => $this->time($value),
            'datetime' => $this->instant($value),
            'json' => $this->customJson($value, $field, $type->valueType),
            default => throw new InvalidArgumentException(
                'A contributed field has no executable portable storage conversion.',
            ),
        };
    }

    private function customJson(mixed $value, FieldDefinition $field, string $valueType): mixed
    {
        $valid = match ($valueType) {
            'string', 'reference' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && !array_is_list($value),
            'collection' => is_array($value) && array_is_list($value),
            default => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException(
                'A contributed JSON field value does not match its declared value family.',
            );
        }

        return $this->boundedJson($value, $field);
    }

    /** @return list<PhysicalColumnBlueprint> */
    private function columns(PhysicalTableBlueprint $table, FieldDefinition $field): array
    {
        $prefix = $field->handle . '.';

        return array_values(array_filter(
            $table->columns(),
            static fn (PhysicalColumnBlueprint $column): bool =>
                $column->logicalName === $field->handle || str_starts_with($column->logicalName, $prefix),
        ));
    }

    /** @return array<string, mixed> */
    private function storageValues(FieldDefinition $field, mixed $value): array
    {
        if ($value === null) {
            return array_fill_keys(array_map(
                static fn (string $suffix): string => $field->handle . $suffix,
                $this->suffixes($field),
            ), null);
        }

        return match ($field->type) {
            'core.decimal' => [$field->handle => $this->exact($value)->value()],
            'core.computed' => [$field->handle => $this->computedStorage($value, $field)],
            'core.money' => [
                $field->handle . '.amount' => $this->moneyValue($value)->amount->value(),
                $field->handle . '.currency' => $this->moneyValue($value)->currency,
            ],
            'core.quantity' => [
                $field->handle . '.amount' => $this->quantityValue($value)->amount->value(),
                $field->handle . '.unit' => $this->quantityValue($value)->unit,
            ],
            'core.zoned_datetime' => [
                $field->handle . '.instant' => $this->zonedValue($value)->instant,
                $field->handle . '.timezone' => $this->zonedValue($value)->timezone,
            ],
            'core.secret' => [
                $field->handle . '.ciphertext' => $this->envelope($value)->ciphertext,
                $field->handle . '.nonce' => $this->envelope($value)->nonce,
                $field->handle . '.key_id' => $this->envelope($value)->keyId,
                $field->handle . '.algorithm' => $this->envelope($value)->algorithm,
            ],
            default => [$field->handle => $value],
        };
    }

    /** @return list<string> */
    private function suffixes(FieldDefinition $field): array
    {
        return match ($field->type) {
            'core.money' => ['.amount', '.currency'],
            'core.quantity' => ['.amount', '.unit'],
            'core.zoned_datetime' => ['.instant', '.timezone'],
            'core.secret' => ['.ciphertext', '.nonce', '.key_id', '.algorithm'],
            default => [''],
        };
    }

    /** @param array<string, mixed> $storage */
    private function fromStorage(
        FieldDefinition $field,
        array $storage,
        string $siteIdentifier,
        string $definitionId,
        string $recordId,
    ): mixed {
        $get = static function (array $values, string $key): mixed {
            if (!array_key_exists($key, $values)) {
                throw new InvalidArgumentException('A stored business field is missing a physical component.');
            }
            return $values[$key];
        };

        return match ($field->type) {
            'core.decimal' => ExactDecimal::fromString(
                (string) $get($storage, $field->handle),
                $this->precision($field),
                $this->scale($field),
            ),
            'core.money' => new MoneyValue(
                ExactDecimal::fromString(
                    (string) $get($storage, $field->handle . '.amount'),
                    $this->precision($field),
                    $this->scale($field),
                ),
                (string) $get($storage, $field->handle . '.currency'),
            ),
            'core.quantity' => new QuantityValue(
                ExactDecimal::fromString(
                    (string) $get($storage, $field->handle . '.amount'),
                    $this->precision($field),
                    $this->scale($field),
                ),
                (string) $get($storage, $field->handle . '.unit'),
            ),
            'core.zoned_datetime' => ZonedDateTimeValue::fromStrings(
                $this->dateTimeString($get($storage, $field->handle . '.instant')),
                (string) $get($storage, $field->handle . '.timezone'),
            ),
            'core.secret' => new EncryptedEnvelope(
                (string) $get($storage, $field->handle . '.ciphertext'),
                (string) $get($storage, $field->handle . '.nonce'),
                (string) $get($storage, $field->handle . '.key_id'),
                (string) $get($storage, $field->handle . '.algorithm'),
            ),
            'core.computed' => $this->computedFromStorage($field, $get($storage, $field->handle)),
            default => $get($storage, $field->handle),
        };
    }

    private function computed(mixed $value, FieldDefinition $field): mixed
    {
        return match ($field->formula?->type) {
            'boolean' => $this->boolean($value),
            'integer' => $this->integer($value),
            'decimal' => $this->decimal($value, $field),
            'string' => $this->boundedString($value, $field->length ?? 191),
            'date' => $this->date($value),
            'time' => $this->time($value),
            'datetime' => $this->instant($value),
            default => throw new InvalidArgumentException('A computed field has an unsupported formula result type.'),
        };
    }

    private function computedStorage(mixed $value, FieldDefinition $field): mixed
    {
        return $field->formula?->type === 'decimal' ? $this->exact($value)->value() : $value;
    }

    private function computedFromStorage(FieldDefinition $field, mixed $value): mixed
    {
        if ($field->formula?->type !== 'decimal') {
            return $value;
        }

        return ExactDecimal::fromString((string) $value, $this->precision($field), $this->scale($field));
    }

    private function dateTimeString(mixed $value): string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        }
        if (is_string($value)) {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        }
        throw new InvalidArgumentException('A stored date-time value is invalid.');
    }

    private function decodePhysical(PhysicalColumnBlueprint $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if (!is_string($contents)) {
                throw new InvalidArgumentException('A stored binary field could not be read.');
            }
            $value = $contents;
        }

        return match ($column->doctrineType) {
            'integer', 'smallint' => is_int($value) ? $value : $this->storedInteger($value),
            'bigint' => is_int($value) || is_string($value)
                ? $value
                : throw new InvalidArgumentException('A stored bigint field is invalid.'),
            'boolean' => $this->storedBoolean($value),
            'date_immutable' => $value instanceof DateTimeImmutable ? $value : $this->date((string) $value),
            'time_immutable' => $value instanceof DateTimeImmutable ? $value : $this->storedTime($value),
            'datetime_immutable', 'datetimetz_immutable' => $value instanceof DateTimeImmutable
                ? $value
                : new DateTimeImmutable((string) $value, new DateTimeZone('UTC')),
            'json' => $this->storedJson($value),
            default => $value,
        };
    }

    private function storedInteger(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('A stored integer field is invalid.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new InvalidArgumentException('A stored integer exceeds the PHP integer range.');
        }

        return $integer;
    }

    private function storedTime(mixed $value): DateTimeImmutable
    {
        if (
            !is_string($value)
            || preg_match('/^(\d{2}:\d{2}:\d{2})(?:\.([0-9]{1,6}))?$/D', $value, $matches) !== 1
        ) {
            throw new InvalidArgumentException('A stored local-time field is invalid.');
        }
        $canonical = $matches[1] . '.' . str_pad($matches[2] ?? '', 6, '0');

        return $this->time($canonical);
    }

    private function storedBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        throw new InvalidArgumentException('A stored boolean field is invalid.');
    }

    private function cursorBigint(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('A stored bigint cursor value is invalid.');
        }

        return $value;
    }

    private function cursorDecimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('A stored decimal cursor value is invalid.');
        }

        return $value;
    }

    private function cursorDateTime(mixed $value): DateTimeImmutable
    {
        if (!$value instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('A stored temporal cursor value is invalid.');
        }

        return $value;
    }

    private function storedJson(mixed $value): mixed
    {
        if (!is_string($value)) {
            RecordValueGuard::assertValue($value);
            return $value;
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A stored JSON field is invalid.', 0, $exception);
        }
        RecordValueGuard::assertValue($decoded);

        return $decoded;
    }

    private function exact(mixed $value): ExactDecimal
    {
        return $value instanceof ExactDecimal
            ? $value
            : throw new InvalidArgumentException('A normalized decimal field is invalid.');
    }

    private function moneyValue(mixed $value): MoneyValue
    {
        return $value instanceof MoneyValue
            ? $value
            : throw new InvalidArgumentException('A normalized money field is invalid.');
    }

    private function quantityValue(mixed $value): QuantityValue
    {
        return $value instanceof QuantityValue
            ? $value
            : throw new InvalidArgumentException('A normalized quantity field is invalid.');
    }

    private function zonedValue(mixed $value): ZonedDateTimeValue
    {
        return $value instanceof ZonedDateTimeValue
            ? $value
            : throw new InvalidArgumentException('A normalized zoned date-time field is invalid.');
    }

    private function envelope(mixed $value): EncryptedEnvelope
    {
        return $value instanceof EncryptedEnvelope
            ? $value
            : throw new InvalidArgumentException('A normalized secret field is invalid.');
    }

    private function precision(FieldDefinition $field): int
    {
        return $field->precision
            ?? throw new InvalidArgumentException('An exact field has no configured precision.');
    }

    private function scale(FieldDefinition $field): int
    {
        return $field->scale ?? throw new InvalidArgumentException('An exact field has no configured scale.');
    }

    private function identityField(EntityTypeDefinition $definition): FieldDefinition
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        foreach ($definition->fields() as $field) {
            if ($field->type === $type) {
                return $field;
            }
        }
        throw new InvalidArgumentException('A business definition has no identity field.');
    }
}
