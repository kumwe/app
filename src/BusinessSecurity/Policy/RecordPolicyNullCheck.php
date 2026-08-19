<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Policy;

use InvalidArgumentException;

/**
 * Test whether a declared record field is null without overloading scalar comparison semantics.
 *
 * @since  2.0.0
 */
final readonly class RecordPolicyNullCheck implements RecordPolicyPredicate
{
    /**
     * Create a null or non-null test for one declared field.
     *
     * @param   string  $field   Stable business-field handle to inspect.
     * @param   bool    $isNull  True to match null, false to match a present non-null value.
     *
     * @throws  InvalidArgumentException  When the field handle is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(public string $field, public bool $isNull = true)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
            throw new InvalidArgumentException('A record-policy field handle is invalid.');
        }
    }

    /**
     * Return the deterministic null-check document used for policy digests.
     *
     * @return  array<string, mixed>  Canonical null-check predicate document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => 'null', 'field' => $this->field, 'is_null' => $this->isNull];
    }

    /**
     * Count this leaf as one policy operation.
     *
     * @return  int  Always one.
     *
     * @since   2.0.0
     */
    public function operationCount(): int
    {
        return 1;
    }

    /**
     * Measure this leaf as a one-level tree.
     *
     * @return  int  Always one.
     *
     * @since   2.0.0
     */
    public function depth(): int
    {
        return 1;
    }
}
