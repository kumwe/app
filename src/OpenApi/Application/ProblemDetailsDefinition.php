<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Application;

use InvalidArgumentException;

/**
 * Immutable public contract for one core RFC 9457 problem type.
 *
 * The definition binds a stable URI to its one HTTP status, retry semantics and closed extension-member
 * schema. Delivery code can vary occurrence detail, but it cannot silently repurpose a code, move it to
 * another status, or add an undocumented machine member after clients have started branching on it.
 *
 * @since  2.0.0
 */
final readonly class ProblemDetailsDefinition
{
    /**
     * Declare one bounded public problem contract.
     *
     * @param   string  $type               Stable `urn:kumwe:problem:` identifier.
     * @param   int     $status             Only HTTP failure status this type may carry.
     * @param   bool    $retryable          Whether retry can succeed without changing intent.
     * @param   ?int    $retryAfterSeconds  Fixed `Retry-After` delay, or null when none applies.
     * @param   array<string, array{
     *              required: bool,
     *              schema: array<string, mixed>
     *          }>                           $extensions         Closed extension-member schemas keyed by name.
     *
     * @throws  InvalidArgumentException  When a type, status, retry rule or extension declaration is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $type,
        public int $status,
        public bool $retryable = false,
        public ?int $retryAfterSeconds = null,
        public array $extensions = [],
    ) {
        if (preg_match('/^urn:kumwe:problem:[a-z][a-z0-9-]{0,126}$/D', $type) !== 1) {
            throw new InvalidArgumentException('A Kumwe problem definition requires a stable problem URN.');
        }
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('A Kumwe problem definition requires a failure status.');
        }
        if ($retryAfterSeconds !== null && (!$retryable || $retryAfterSeconds < 1 || $retryAfterSeconds > 86400)) {
            throw new InvalidArgumentException('A Kumwe problem retry delay is invalid.');
        }
        foreach ($extensions as $name => $extension) {
            if (
                preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1
                || !is_bool($extension['required'] ?? null)
                || !is_array($extension['schema'] ?? null)
                || array_is_list($extension['schema'])
            ) {
                throw new InvalidArgumentException('A Kumwe problem extension declaration is invalid.');
            }
        }
    }

    /**
     * Validate the extension members a response wants to publish.
     *
     * The core currently owns one structured extension: validation violations. Its shape is checked here as
     * well as in the generated JSON Schema so a broken server response is refused before it reaches a client.
     * A new extension therefore requires an explicit registry generation instead of appearing accidentally.
     *
     * @param   array<string, bool|int|float|string|array<mixed>|null>  $extensions  Candidate response members.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a member is undeclared, required but absent, or malformed.
     *
     * @since   2.0.0
     */
    public function validateExtensions(array $extensions): void
    {
        foreach ($extensions as $name => $value) {
            if (!isset($this->extensions[$name])) {
                throw new InvalidArgumentException('The problem type does not declare this extension member.');
            }
            if ($name === 'violations') {
                $this->validateViolations($value);
            }
        }
        foreach ($this->extensions as $name => $definition) {
            if ($definition['required'] && !array_key_exists($name, $extensions)) {
                throw new InvalidArgumentException('The problem type is missing a required extension member.');
            }
        }
    }

    /**
     * Export this definition as the versioned machine registry stores it.
     *
     * @return  array{
     *              type: string,
     *              status: int,
     *              retryable: bool,
     *              retry_after_seconds: ?int,
     *              extensions: array<string, array{required: bool, schema: array<string, mixed>}>
     *          }  Canonical registry row.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'retryable' => $this->retryable,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'extensions' => $this->extensions,
        ];
    }

    /**
     * Check the one structured extension currently owned by the core.
     *
     * @param   mixed  $value  Candidate `violations` value.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the collection or one closed violation row is invalid.
     *
     * @since   2.0.0
     */
    private function validateViolations(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 256) {
            throw new InvalidArgumentException('Problem violations must be a non-empty bounded list.');
        }
        foreach ($value as $violation) {
            if (
                !is_array($violation)
                || array_is_list($violation)
                || array_keys($violation) !== ['field', 'code', 'message']
                || !is_string($violation['field'])
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $violation['field']) !== 1
                || !is_string($violation['code'])
                || preg_match('/^[a-z][a-z0-9._-]{0,126}$/D', $violation['code']) !== 1
                || !is_string($violation['message'])
                || $violation['message'] === ''
                || strlen($violation['message']) > 1000
            ) {
                throw new InvalidArgumentException('A problem validation violation is malformed.');
            }
        }
    }
}
