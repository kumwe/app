<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Application;

use InvalidArgumentException;

/**
 * Finite registry of the core problem codes public REST clients may branch on.
 *
 * This is the runtime source for the checked-in `api/problem-details/kumwe-v1.json` generation. The
 * generated OpenAPI problem union is assembled from the same rows, so status, retry and extension semantics
 * cannot diverge between handler validation, the standalone registry and the REST schema.
 *
 * @since  2.0.0
 */
final readonly class ProblemDetailsRegistry
{
    /**
     * Resolve one stable problem definition.
     *
     * @param   string  $type  Candidate problem type URI.
     *
     * @return  ?ProblemDetailsDefinition  Registered core definition, or null for non-core problem URIs.
     *
     * @since   2.0.0
     */
    public function find(string $type): ?ProblemDetailsDefinition
    {
        foreach ($this->definitions() as $definition) {
            if ($definition->type === $type) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Require one core definition and prove the status and extensions of an occurrence.
     *
     * @param   string                                                  $type        Kumwe problem type.
     * @param   int                                                     $status      Response status.
     * @param   array<string, bool|int|float|string|array<mixed>|null>  $extensions  Public extension members.
     *
     * @return  ProblemDetailsDefinition  Exact registered definition.
     *
     * @throws  InvalidArgumentException  When the code is unknown or its occurrence contradicts the registry.
     *
     * @since   2.0.0
     */
    public function require(string $type, int $status, array $extensions): ProblemDetailsDefinition
    {
        $definition = $this->find($type);
        if ($definition === null) {
            throw new InvalidArgumentException('The Kumwe problem type is not registered.');
        }
        if ($definition->status !== $status) {
            throw new InvalidArgumentException('The Kumwe problem type cannot be emitted with this status.');
        }
        $definition->validateExtensions($extensions);

        return $definition;
    }

    /**
     * Prove that one generic RFC-defined occurrence stays inside its closed extension contract.
     *
     * `about:blank` deliberately exposes no application branch code. Its only optional extension is the
     * request correlation identifier used by the outer HTTP error boundary; accepting any other member
     * would make the runtime response wider than the retained OpenAPI union.
     *
     * @param   array<string, bool|int|float|string|array<mixed>|null>  $extensions  Candidate response members.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a member is undeclared or the request id is not a bounded
     *          UTF-8 string.
     *
     * @since   2.0.0
     */
    public function validateAboutBlankExtensions(array $extensions): void
    {
        foreach ($extensions as $name => $value) {
            if ($name !== 'request_id') {
                throw new InvalidArgumentException(
                    'The about:blank problem type does not declare this extension member.',
                );
            }
            if (
                !is_string($value)
                || !mb_check_encoding($value, 'UTF-8')
                || mb_strlen($value, 'UTF-8') < 1
                || mb_strlen($value, 'UTF-8') > 191
            ) {
                throw new InvalidArgumentException(
                    'The about:blank request id must be a non-empty bounded UTF-8 string.',
                );
            }
        }
    }

    /**
     * Return the stable registry generation in URI order.
     *
     * Four business-record idempotency entries are deliberately explicit here even though the application
     * exception constructs its code from a closed state enum. Expansion at this boundary makes the public
     * vocabulary finite and prevents a future internal state from becoming a public code by concatenation.
     *
     * @return  list<ProblemDetailsDefinition>  Every core problem definition, sorted by stable URI.
     *
     * @since   2.0.0
     */
    public function definitions(): array
    {
        /** @var list<array{string, int, bool, ?int}> $rows */
        $rows = [
            ['authentication-throttled', 429, true, 900],
            ['authorization-denied', 403, false, null],
            ['automation-not-found', 404, false, null],
            ['business-approval-not-found', 404, false, null],
            ['business-definition-not-found', 404, false, null],
            ['business-operation-not-found', 404, false, null],
            ['business-record-action-rejected', 422, false, null],
            ['business-record-idempotency-corrupt', 409, false, null],
            ['business-record-idempotency-in-progress', 409, true, 1],
            ['business-record-idempotency-key-reused', 409, false, null],
            ['business-record-idempotency-replay-window-elapsed', 409, false, null],
            ['business-record-immutable', 409, false, null],
            ['business-record-not-found', 404, false, null],
            ['business-record-posting-period-closed', 409, false, null],
            ['business-record-posting-period-undeclared', 409, false, null],
            ['business-record-reference-conflict', 409, false, null],
            ['business-record-unavailable', 503, true, 1],
            ['business-record-unique-conflict', 409, false, null],
            ['business-relationship-rejected', 422, false, null],
            ['business-report-row-limit-exceeded', 422, false, null],
            ['business-report-validation-failed', 422, false, null],
            ['business-schema-conflict', 409, false, null],
            ['business-schema-not-found', 404, false, null],
            ['content-not-found', 404, false, null],
            ['high-impact-authentication-required', 403, false, null],
            ['idempotency-authorization-changed', 409, false, null],
            ['idempotency-in-progress', 409, true, null],
            ['idempotency-key-required', 400, false, null],
            ['idempotency-key-reused', 422, false, null],
            ['insufficient-capability', 403, false, null],
            ['invalid-business-operation', 422, false, null],
            ['invalid-business-record-query', 422, false, null],
            ['invalid-business-report-export', 422, false, null],
            ['invalid-idempotency-key', 400, false, null],
            ['invalid-if-match', 400, false, null],
            ['invalid-plan-request', 400, false, null],
            ['navigation-not-found', 404, false, null],
            ['openapi-contract-unavailable', 503, true, 30],
            ['posting-period-conflict', 409, false, null],
            ['precondition-failed', 412, false, null],
            ['precondition-required', 428, false, null],
            ['step-up-required', 403, false, null],
            ['validation-failed', 422, false, null],
        ];
        $definitions = [];
        foreach ($rows as [$code, $status, $retryable, $retryAfterSeconds]) {
            $definitions[] = new ProblemDetailsDefinition(
                'urn:kumwe:problem:' . $code,
                $status,
                $retryable,
                $retryAfterSeconds,
            );
        }
        $definitions[] = new ProblemDetailsDefinition(
            'urn:kumwe:problem:business-record-validation-failed',
            422,
            extensions: ['violations' => [
                'required' => true,
                'schema' => self::violationsSchema(),
            ]],
        );
        usort(
            $definitions,
            static fn (ProblemDetailsDefinition $left, ProblemDetailsDefinition $right): int =>
                $left->type <=> $right->type,
        );

        return $definitions;
    }

    /**
     * Export the standalone versioned registry document.
     *
     * @return  array{
     *              format: string,
     *              generation: string,
     *              problems: list<array{
     *                  type: string,
     *                  status: int,
     *                  retryable: bool,
     *                  retry_after_seconds: ?int,
     *                  extensions: array<string, array{required: bool, schema: array<string, mixed>}>|\stdClass
     *              }>
     *          }  Machine-readable public registry generation.
     *
     * @since   2.0.0
     */
    public function document(): array
    {
        return [
            'format' => 'kumwe-problem-details-registry-v1',
            'generation' => '1.0.0',
            'problems' => array_map(
                static function (ProblemDetailsDefinition $definition): array {
                    $source = $definition->toArray();

                    return [
                        'type' => $source['type'],
                        'status' => $source['status'],
                        'retryable' => $source['retryable'],
                        'retry_after_seconds' => $source['retry_after_seconds'],
                        'extensions' => $source['extensions'] === []
                            ? new \stdClass()
                            : $source['extensions'],
                    ];
                },
                $this->definitions(),
            ),
        ];
    }

    /**
     * Build the closed OpenAPI union for stable core types plus RFC-defined `about:blank` occurrences.
     *
     * @return  array<string, mixed>  OpenAPI 3.1 JSON Schema for every problem response.
     *
     * @since   2.0.0
     */
    public function openApiSchema(): array
    {
        $schemas = [self::aboutBlankSchema()];
        foreach ($this->definitions() as $definition) {
            $properties = self::baseProperties();
            $properties['type'] = ['const' => $definition->type, 'type' => 'string'];
            $properties['status'] = ['const' => $definition->status, 'type' => 'integer'];
            $required = ['type', 'title', 'status', 'detail'];
            foreach ($definition->extensions as $name => $extension) {
                $properties[$name] = $extension['schema'];
                if ($extension['required']) {
                    $required[] = $name;
                }
            }
            sort($required, SORT_STRING);
            ksort($properties, SORT_STRING);
            $schemas[] = [
                'additionalProperties' => false,
                'properties' => $properties,
                'required' => $required,
                'type' => 'object',
            ];
        }

        return ['oneOf' => $schemas];
    }

    /**
     * Define common RFC 9457 members before a type specializes `type` and `status`.
     *
     * @return  array<string, array<string, mixed>>  Base problem properties keyed by member name.
     *
     * @since   2.0.0
     */
    private static function baseProperties(): array
    {
        return [
            'detail' => ['maxLength' => 4000, 'minLength' => 1, 'type' => 'string'],
            'instance' => ['format' => 'uri-reference', 'maxLength' => 2048, 'type' => 'string'],
            'status' => ['maximum' => 599, 'minimum' => 400, 'type' => 'integer'],
            'title' => ['maxLength' => 200, 'minLength' => 1, 'type' => 'string'],
            'type' => ['format' => 'uri-reference', 'type' => 'string'],
        ];
    }

    /**
     * Describe generic RFC-defined failures that intentionally expose no Kumwe branch code.
     *
     * @return  array<string, mixed>  Closed `about:blank` schema with optional correlation identifier.
     *
     * @since   2.0.0
     */
    private static function aboutBlankSchema(): array
    {
        $properties = self::baseProperties();
        $properties['type'] = ['const' => 'about:blank', 'type' => 'string'];
        $properties['request_id'] = ['maxLength' => 191, 'minLength' => 1, 'type' => 'string'];
        ksort($properties, SORT_STRING);

        return [
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => ['detail', 'status', 'title', 'type'],
            'type' => 'object',
        ];
    }

    /**
     * Describe the caller-safe validation evidence attached to record validation failures.
     *
     * @return  array<string, mixed>  Bounded list of closed field/code/message rows.
     *
     * @since   2.0.0
     */
    private static function violationsSchema(): array
    {
        return [
            'items' => [
                'additionalProperties' => false,
                'properties' => [
                    'code' => [
                        'maxLength' => 127,
                        'pattern' => '^[a-z][a-z0-9._-]{0,126}$',
                        'type' => 'string',
                    ],
                    'field' => [
                        'maxLength' => 63,
                        'pattern' => '^[a-z][a-z0-9_]{0,62}$',
                        'type' => 'string',
                    ],
                    'message' => ['maxLength' => 1000, 'minLength' => 1, 'type' => 'string'],
                ],
                'required' => ['code', 'field', 'message'],
                'type' => 'object',
            ],
            'maxItems' => 256,
            'minItems' => 1,
            'type' => 'array',
        ];
    }
}
