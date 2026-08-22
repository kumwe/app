<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;

/**
 * Stable redacted document returned inside an MCP tool-level error result.
 *
 * Exception messages, class names, request arguments and identifiers are deliberately absent. The mapper
 * selects every member from a closed vocabulary, so an application refusal remains useful to an MCP client
 * without turning SQL, paths, credentials, policy evidence or caller data into model-visible output.
 *
 * @since  2.0.0
 */
final readonly class McpToolErrorEnvelope implements JsonSerializable
{
    /**
     * Build one validated error document.
     *
     * @param   string  $code       Stable dotted machine code selected by the mapper.
     * @param   string  $message    Fixed safe sentence selected by the mapper.
     * @param   bool    $retryable  Whether retrying later without changing the request may succeed.
     *
     * @throws  InvalidArgumentException  When a mapper attempts to publish an invalid code or message.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $code,
        public string $message,
        public bool $retryable = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $this->code) !== 1) {
            throw new InvalidArgumentException('An MCP tool error code must be a dotted lowercase identifier.');
        }
        if ($this->message === '' || strlen($this->message) > 240) {
            throw new InvalidArgumentException('An MCP tool error message must contain 1 to 240 bytes.');
        }
    }

    /**
     * Return members in the frozen wire order.
     *
     * @return  array{schema: string, code: string, message: string, retryable: bool}  Redacted error document.
     *
     * @since   2.0.0
     */
    public function jsonSerialize(): array
    {
        return [
            'schema' => McpMachineContract::ERROR_SCHEMA,
            'code' => $this->code,
            'message' => $this->message,
            'retryable' => $this->retryable,
        ];
    }

    /**
     * Encode the document as compact JSON for the SDK's text content item.
     *
     * @return  string  Stable UTF-8 JSON with no escaping differences between transports.
     *
     * @throws  JsonException  When JSON encoding fails.
     *
     * @since   2.0.0
     */
    public function toJson(): string
    {
        return json_encode(
            $this,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
