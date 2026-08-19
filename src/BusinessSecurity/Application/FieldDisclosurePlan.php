<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application;

use InvalidArgumentException;

/**
 * Explicit per-use field allow-list for one resolved record resource.
 *
 * Every usage absent from the constructor is intentionally empty. In particular an empty detail or
 * list set means the actor sees zero values; it never expands to all definition fields.
 *
 * @since  2.0.0
 */
final readonly class FieldDisclosurePlan
{
    /**
     * Canonical field handles keyed by every supported disclosure usage.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private array $allowed;

    /**
     * Create an explicit, canonical field allow-list for every usage.
     *
     * @param   array<string, list<string>>  $allowed  Field handles keyed by `FieldAccessUsage::value`.
     *
     * @throws  InvalidArgumentException  When a usage, handle, list shape, or overall field count is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(array $allowed = [])
    {
        if ($allowed !== [] && array_is_list($allowed)) {
            throw new InvalidArgumentException('A field-disclosure plan must be keyed by usage.');
        }
        $normalized = [];
        $total = 0;
        foreach (FieldAccessUsage::cases() as $usage) {
            $fields = $allowed[$usage->value] ?? [];
            if (!is_array($fields) || !array_is_list($fields) || count($fields) > 256) {
                throw new InvalidArgumentException('A field-disclosure usage has an invalid field list.');
            }
            foreach ($fields as $field) {
                if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
                    throw new InvalidArgumentException('A field-disclosure plan contains an invalid handle.');
                }
            }
            $fields = array_values(array_unique($fields));
            sort($fields, SORT_STRING);
            $normalized[$usage->value] = $fields;
            $total += count($fields);
        }
        foreach (array_keys($allowed) as $usage) {
            if (!is_string($usage) || FieldAccessUsage::tryFrom($usage) === null) {
                throw new InvalidArgumentException('A field-disclosure plan contains an unknown usage.');
            }
        }
        if ($total > count(FieldAccessUsage::cases()) * 256) {
            throw new InvalidArgumentException('A field-disclosure plan exceeds its total field bound.');
        }
        $this->allowed = $normalized;
    }

    /**
     * Report whether one field may be used in one way.
     *
     * @param   FieldAccessUsage  $usage  Read, write, query, export, or audit use being attempted.
     * @param   string            $field  Stable field handle.
     *
     * @return  bool  True only when the plan names the field for that exact usage.
     *
     * @since   2.0.0
     */
    public function allows(FieldAccessUsage $usage, string $field): bool
    {
        return in_array($field, $this->allowed[$usage->value], true);
    }

    /**
     * List every field allowed for one use.
     *
     * @param   FieldAccessUsage  $usage  Use whose explicit set is requested.
     *
     * @return  list<string>  Canonically sorted field handles, possibly empty.
     *
     * @since   2.0.0
     */
    public function fields(FieldAccessUsage $usage): array
    {
        return $this->allowed[$usage->value];
    }

    /**
     * Return the deterministic disclosure document used by an access-plan digest.
     *
     * @return  array<string, list<string>>  Every usage, including explicit empty sets.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return $this->allowed;
    }
}
