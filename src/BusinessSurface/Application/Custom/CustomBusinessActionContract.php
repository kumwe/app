<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application\Custom;

use InvalidArgumentException;

/**
 * Signed declaration pairing one custom action handler with command and result schemas.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessActionContract
{
    /**
     * Validate one custom action contract.
     *
     * @param   string                $handler        Owner-scoped reference used to register the handler.
     * @param   string                $schema         Owner-scoped identity of this schema pair.
     * @param   CustomBusinessSchema  $commandSchema  Closed schema applied before handler invocation.
     * @param   CustomBusinessSchema  $resultSchema   Closed schema applied to the returned result data.
     *
     * @throws  InvalidArgumentException  When a reference is unsafe or both references are identical.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $handler,
        public string $schema,
        public CustomBusinessSchema $commandSchema,
        public CustomBusinessSchema $resultSchema,
    ) {
        CustomBusinessReference::assert($handler, 'action handler');
        CustomBusinessReference::assert($schema, 'action schema');
        if ($handler === $schema) {
            throw new InvalidArgumentException('A custom business action handler and schema need distinct references.');
        }
    }

    /**
     * Parse one strict manifest declaration.
     *
     * @param   array<string, mixed>  $document  Decoded `action_handlers` entry.
     *
     * @return  self  Validated contract with canonical schemas.
     *
     * @throws  InvalidArgumentException  When a key is unknown or any required member is malformed.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), ['handler', 'schema', 'command_schema', 'result_schema']) !== []) {
            throw new InvalidArgumentException('A custom business action contract contains an unknown property.');
        }

        return new self(
            self::string($document, 'handler'),
            self::string($document, 'schema'),
            CustomBusinessSchema::fromArray($document['command_schema'] ?? null),
            CustomBusinessSchema::fromArray($document['result_schema'] ?? null),
        );
    }

    /**
     * Export the signed and provider-reconciled contract.
     *
     * @return  array{
     *              handler: string,
     *              schema: string,
     *              command_schema: array<string, mixed>,
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
            'command_schema' => $this->commandSchema->toArray(),
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
            throw new InvalidArgumentException('A custom business action contract requires ' . $key . '.');
        }
        return trim($value);
    }
}
