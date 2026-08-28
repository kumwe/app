<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Policy;

use InvalidArgumentException;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparison;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyPredicate;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyValueType;

/**
 * Closed field/type vocabulary against which a record-policy tree is validated.
 *
 * @since  2.0.0
 */
final readonly class RecordPolicySchema
{
    /**
     * Canonical mapping of stable field handles to their exact policy scalar types.
     *
     * @var    array<string, RecordPolicyValueType>
     * @since  2.0.0
     */
    private array $fields;

    /**
     * Create and canonicalize a closed field/type vocabulary.
     *
     * @param   array<string, RecordPolicyValueType>  $fields  Field handles and exact comparable types.
     *
     * @throws  InvalidArgumentException  When the schema is oversized, unordered data is malformed, or a
     *          field handle/type is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(array $fields)
    {
        if (count($fields) > 256 || ($fields !== [] && array_is_list($fields))) {
            throw new InvalidArgumentException('A record-policy schema must be an object of at most 256 fields.');
        }
        foreach ($fields as $handle => $type) {
            if (
                !is_string($handle)
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1
                || !$type instanceof RecordPolicyValueType
            ) {
                throw new InvalidArgumentException('A record-policy schema field is invalid.');
            }
        }
        ksort($fields, SORT_STRING);
        $this->fields = $fields;
    }

    /**
     * Validate every reference and comparison type in one predicate.
     *
     * @param   RecordPolicyPredicate  $predicate  Bounded declarative predicate to validate.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a field is undeclared or a literal has another type.
     *
     * @since   2.0.0
     */
    public function assertPredicate(RecordPolicyPredicate $predicate): void
    {
        if ($predicate instanceof RecordPolicyComparison) {
            $declared = $this->fields[$predicate->field] ?? null;
            if ($declared === null || $declared !== $predicate->valueType) {
                throw new InvalidArgumentException('A record-policy comparison field or type is unavailable.');
            }
            return;
        }
        if ($predicate instanceof RecordPolicyNullCheck) {
            if (!isset($this->fields[$predicate->field])) {
                throw new InvalidArgumentException('A record-policy null-check field is unavailable.');
            }
            return;
        }
        if ($predicate instanceof RecordPolicyBoolean) {
            foreach ($predicate->children as $child) {
                $this->assertPredicate($child);
            }
            return;
        }
        if (!$predicate instanceof RecordPolicyConstant) {
            throw new InvalidArgumentException('A record-policy predicate type is unsupported.');
        }
    }

    /**
     * Return the type declared for a policy field.
     *
     * @param   string  $field  Field handle to resolve.
     *
     * @return  RecordPolicyValueType  Exact policy scalar type.
     *
     * @throws  InvalidArgumentException  When the schema does not declare the field.
     *
     * @since   2.0.0
     */
    public function type(string $field): RecordPolicyValueType
    {
        return $this->fields[$field]
            ?? throw new InvalidArgumentException('A record-policy field is unavailable.');
    }

    /**
     * Return the canonical field/type map.
     *
     * @return  array<string, string>  Field handles keyed to scalar type identifiers.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return array_map(static fn (RecordPolicyValueType $type): string => $type->value, $this->fields);
    }
}
