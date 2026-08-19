<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Domain;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\CanonicalJson;

/**
 * Shared syntax and bounded-JSON checks for trusted integration declarations.
 *
 * @since  2.0.0
 */
final class IntegrationContractValidator
{
    /**
     * Reject unknown or missing keys before a manifest array becomes a definition.
     *
     * @param   array<string, mixed>  $data      Definition representation.
     * @param   list<string>          $required  Complete allowed and required key set.
     * @param   string                $label     Diagnostic definition name.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When keys differ from the declared closed shape.
     *
     * @since   2.0.0
     */
    public static function keys(array $data, array $required, string $label): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw new InvalidArgumentException(sprintf('%s has missing or unknown fields.', $label));
        }
    }

    /**
     * Read a required string from the supplied data.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     * @param   string                $key   Array or row key whose value is being read.
     *
     * @return  string  Required string stored under the requested key.
     *
     * @since   2.0.0
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Definition field "%s" must be a string.', $key));
        }
        return $value;
    }

    /**
     * Read and validate an integer value.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     * @param   string                $key   Array or row key whose value is being read.
     *
     * @return  int  Integer stored under the requested key.
     *
     * @since   2.0.0
     */
    public static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Definition field "%s" must be an integer.', $key));
        }
        return $value;
    }

    /**
     * Read and validate a boolean value.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     * @param   string                $key   Array or row key whose value is being read.
     *
     * @return  bool  Boolean stored under the requested key.
     *
     * @since   2.0.0
     */
    public static function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('Definition field "%s" must be a boolean.', $key));
        }
        return $value;
    }

    /**
     * Read an object-valued field from the supplied contract.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     * @param   string                $key   Array or row key whose value is being read.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
    public static function objectField(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException(sprintf('Definition field "%s" must be an object.', $key));
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Read a list-valued field from the supplied contract.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     * @param   string                $key   Array or row key whose value is being read.
     *
     * @return  list<mixed>
     *
     * @since   2.0.0
     */
    public static function listField(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Definition field "%s" must be a list.', $key));
        }
        return $value;
    }

    /**
     * Require a namespaced contribution identifier.
     *
     * @param   string  $value  Identifier to check.
     * @param   string  $label  Diagnostic field name.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is unsafe or not namespaced.
     *
     * @since   2.0.0
     */
    public static function identifier(string $value, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){1,15}$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must be a namespaced identifier.', $label));
        }
    }

    /**
     * Require a short safe token, optionally without a namespace.
     *
     * @param   string  $value  Token to check.
     * @param   string  $label  Diagnostic field name.
     * @param   int     $limit  Maximum byte length.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the token is empty or contains unsafe bytes.
     *
     * @since   2.0.0
     */
    public static function token(string $value, string $label, int $limit = 191): void
    {
        if (
            $value === ''
            || strlen($value) > $limit
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException(sprintf('%s is invalid.', $label));
        }
    }

    /**
     * Prove a JSON object is canonical and within its declaration limit.
     *
     * @param   array<string, mixed>  $value  JSON object to validate.
     * @param   string                $label  Diagnostic field name.
     * @param   int                   $limit  Maximum encoded bytes.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is a list, not canonical JSON, or too large.
     *
     * @since   2.0.0
     */
    public static function object(array $value, string $label, int $limit = 32_768): void
    {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a JSON object.', $label));
        }
        if (strlen(CanonicalJson::encode($value)) > $limit) {
            throw new InvalidArgumentException(sprintf('%s exceeds the encoded size limit.', $label));
        }
    }

    /**
     * Normalize and validate a non-empty unique list of namespaced identifiers.
     *
     * @param   list<string>  $values  Identifiers to normalize.
     * @param   string        $label   Diagnostic field name.
     *
     * @return  list<string>  Sorted unique identifiers.
     *
     * @throws  InvalidArgumentException  When the list is empty or contains an invalid value.
     *
     * @since   2.0.0
     */
    public static function identifiers(array $values, string $label): array
    {
        if ($values === []) {
            throw new InvalidArgumentException(sprintf('%s cannot be empty.', $label));
        }
        foreach ($values as $value) {
            self::identifier($value, $label);
        }
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);
        return $values;
    }
}
