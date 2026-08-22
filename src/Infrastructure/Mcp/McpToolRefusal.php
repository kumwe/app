<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use RuntimeException;

/**
 * Explicit application-level MCP refusal whose code and wording are safe for the tool envelope.
 *
 * Runtime defects must keep using their original exceptions so the SDK logs them and answers generically.
 * This type is reserved for a condition the handler deliberately models and expects a client to act on. The
 * mapper publishes it only when code, sentence and retry decision exactly match `McpToolErrorVocabulary`; a
 * newly constructed tuple is not public merely because a handler placed it in this exception.
 *
 * @since  2.0.0
 */
final class McpToolRefusal extends RuntimeException
{
    /**
     * Capture closed safe output selected at the point the condition is detected.
     *
     * @param  string  $stableCode   Dotted machine code retained by `McpToolErrorVocabulary`.
     * @param  string  $safeMessage  Exact retained redacted sentence safe to return to a model.
     * @param  bool    $retryable    Retained decision on whether an unchanged later attempt may succeed.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $stableCode,
        public readonly string $safeMessage,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($safeMessage);
    }
}
