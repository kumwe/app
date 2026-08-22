<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Throwable;

/**
 * Maps expected application refusals onto stable MCP tool results and leaves defects untouched.
 *
 * Returning `CallToolResult::error()` is the official SDK v0.7.1 path that serializes `isError: true`.
 * Returning null is equally deliberate: the reference-handler decorator rethrows that throwable, after
 * which the SDK logs the exception and emits its generic protocol error without exposing the message.
 *
 * @since  2.0.0
 */
final readonly class McpToolErrorMapper
{
    /**
     * Classify a throwable only when it is an expected client-visible refusal.
     *
     * @param   Throwable  $exception  Failure raised by a registered tool handler.
     *
     * @return  ?CallToolResult  Redacted `isError` result, or null for an unexpected defect.
     *
     * @since   2.0.0
     */
    public function map(Throwable $exception): ?CallToolResult
    {
        $envelope = McpToolErrorVocabulary::envelope($exception);
        if ($envelope === null) {
            return null;
        }

        return CallToolResult::error([new TextContent($envelope->toJson())]);
    }
}
