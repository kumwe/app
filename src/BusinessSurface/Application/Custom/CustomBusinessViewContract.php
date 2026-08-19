<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application\Custom;

use InvalidArgumentException;

/**
 * Signed declaration pairing one custom view handler with its query and result schemas.
 *
 * The handler and schema identifiers are separate so published business definitions bind both the
 * executable collaborator and the exact contract they were compiled against. A provider registers an
 * equal contract with a typed handler; the owner-aware registrar rejects drift from these signed bytes.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessViewContract
{
    /**
     * Validate one custom view contract.
     *
     * @param   string                $handler       Owner-scoped reference used to register the handler.
     * @param   string                $schema        Owner-scoped identity of this schema pair.
     * @param   CustomBusinessSchema  $querySchema   Closed schema applied before handler invocation.
     * @param   CustomBusinessSchema  $resultSchema  Closed schema applied to the returned result data.
     *
     * @throws  InvalidArgumentException  When a reference is unsafe or both references are identical.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $handler,
        public string $schema,
        public CustomBusinessSchema $querySchema,
        public CustomBusinessSchema $resultSchema,
    ) {
        CustomBusinessReference::assert($handler, 'view handler');
        CustomBusinessReference::assert($schema, 'view schema');
        if ($handler === $schema) {
            throw new InvalidArgumentException('A custom business view handler and schema need distinct references.');
        }
    }

    /**
     * Parse one strict manifest declaration.
     *
     * @param   array<string, mixed>  $document  Decoded `view_handlers` entry.
     *
     * @return  self  Validated contract with canonical schemas.
     *
     * @throws  InvalidArgumentException  When a key is unknown or any required member is malformed.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), ['handler', 'schema', 'query_schema', 'result_schema']) !== []) {
            throw new InvalidArgumentException('A custom business view contract contains an unknown property.');
        }

        return new self(
            self::string($document, 'handler'),
            self::string($document, 'schema'),
            CustomBusinessSchema::fromArray($document['query_schema'] ?? null),
            CustomBusinessSchema::fromArray($document['result_schema'] ?? null),
        );
    }

    /**
     * Export the signed and provider-reconciled contract.
     *
     * @return  array{
     *              handler: string,
     *              schema: string,
     *              query_schema: array<string, mixed>,
     *              result_schema: array<string, mixed>
     *          }
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'handler' => $this->handler,
            'schema' => $this->schema,
            'query_schema' => $this->querySchema->toArray(),
            'result_schema' => $this->resultSchema->toArray(),
        ];
    }

    /**
     * Read a required non-empty string from a contract declaration.
     *
     * @param   array<string, mixed>  $document  Contract object being parsed.
     * @param   string                $key       Required member name.
     *
     * @return  string  Trimmed declared value.
     *
     * @throws  InvalidArgumentException  When the member is absent, non-string, or blank.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('A custom business view contract requires ' . $key . '.');
        }
        return trim($value);
    }
}
