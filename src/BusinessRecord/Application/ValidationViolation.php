<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;

/**
 * One field-level reason a record was rejected, in a shape the delivery layer can render.
 *
 * `RecordRuleValidator` and `BusinessRecordService` collect these as they check a submitted value set and
 * hand the whole list to `BusinessRecordValidationFailed`, so a caller learns every problem with a
 * submission at once instead of one per round trip. The triple is deliberately split: `$field` addresses
 * the input control, `$code` is the stable token a client branches on or translates, and `$message` is
 * the operator-readable fallback. Construction enforces that split — a bounded lowercase handle, a bounded
 * dotted code, a non-empty message — so a client can branch on the code instead of parsing prose.
 *
 * @since  2.0.0
 */
final readonly class ValidationViolation
{
    /**
     * Record one rejection against one field.
     *
     * @param   string  $field    Handle of the offending field; `scope` is used for whole-record scope
     *          failures that belong to no single field.
     * @param   string  $code     Stable machine token for the rule that failed, such as `required` or
     *          `invalid_type`.
     * @param   string  $message  Sentence explaining the failure to a person, safe to show back.
     *
     * @throws  InvalidArgumentException  When the field handle or code is not a bounded lowercase
     *          identifier, or the message is empty.
     *
     * @since   2.0.0
     */
    public function __construct(public string $field, public string $code, public string $message)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
            throw new InvalidArgumentException('A validation violation field is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{0,126}$/D', $code) !== 1 || $message === '') {
            throw new InvalidArgumentException('A validation violation code or message is invalid.');
        }
    }

    /**
     * Flatten the violation into the payload an error response carries.
     *
     * @return  array{field: string, code: string, message: string}  The three parts keyed by name, ready
     *          to serialize into an error body without further mapping.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'code' => $this->code, 'message' => $this->message];
    }
}
