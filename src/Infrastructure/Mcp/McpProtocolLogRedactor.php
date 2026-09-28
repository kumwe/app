<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\MessageInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * Keeps MCP message and tool payloads out of the log channel the protocol SDK writes to.
 *
 * The SDK logs every inbound message twice at `info`, the application's default level: once as the raw
 * JSON-RPC text and once as the decoded request. It logs tool arguments and structured results at
 * `debug`, and tool arguments again when a tool raises its own error. Those payloads carry record values,
 * content bodies and personal data that the tool's policy governs, yet a log reader holds none of that
 * authority. The host's key-based redaction cannot see inside them either: the raw text is one opaque
 * string, and the decoded request is an object the JSON formatter serializes only after every processor
 * has run. This decorator therefore sits between the SDK and the application logger and replaces every
 * payload-bearing context entry with a structural summary — the protocol method the SDK itself declares,
 * the payload's kind and its size — so an operator still sees which operation arrived and when, without
 * the log becoming a second, policy-free copy of the data the operation carried. Level, message and every
 * other context entry pass through unchanged, and the application logger's own processors still run.
 *
 * @since  2.0.0
 */
final readonly class McpProtocolLogRedactor implements LoggerInterface
{
    use LoggerTrait;

    /**
     * Context keys under which the SDK records message or tool payloads.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array PAYLOAD_KEYS = [
        'message',
        'request',
        'notification',
        'response',
        'arguments',
        'structured_content',
    ];

    /**
     * Wrap the application logger that finally receives the summarized records.
     *
     * @param  LoggerInterface  $inner  Application logger, with its redaction and context processors.
     *
     * @since  2.0.0
     */
    public function __construct(private LoggerInterface $inner)
    {
    }

    /**
     * Forward one SDK record with each payload-bearing context entry replaced by its summary.
     *
     * @param   mixed                $level    PSR-3 level the SDK chose, forwarded unchanged.
     * @param   string|Stringable    $message  SDK message, forwarded unchanged.
     * @param   array<mixed, mixed>  $context  SDK context whose payload entries are summarized.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        foreach (self::PAYLOAD_KEYS as $key) {
            if (array_key_exists($key, $context)) {
                $context[$key] = self::summary($context[$key]);
            }
        }

        $this->inner->log($level, $message, $context);
    }

    /**
     * Describe a payload by its shape alone.
     *
     * A decoded request or notification names its method through the SDK's own static declaration, so
     * the method recorded is always one of the protocol's fixed names and never a client-supplied value.
     *
     * @param   mixed  $payload  Raw message text, decoded message, tool arguments or tool result.
     *
     * @return  array{kind: string, method?: string, bytes?: int}  Kind, and the method or byte size where
     *          the payload carries one.
     *
     * @since   2.0.0
     */
    private static function summary(mixed $payload): array
    {
        if ($payload instanceof HasMethodInterface) {
            return ['kind' => 'message', 'method' => $payload::getMethod()];
        }
        if ($payload instanceof MessageInterface) {
            return ['kind' => 'message'];
        }
        if (is_string($payload)) {
            return ['kind' => 'text', 'bytes' => strlen($payload)];
        }

        return ['kind' => get_debug_type($payload)];
    }
}
