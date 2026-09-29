<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Kumwe\App\Application\Diagnostics\OperatorDiagnostics;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Read-only MCP delegate to the shared installation-wide operator authority.
 *
 * @since  2.0.0
 */
final readonly class OperatorDiagnosticsMcpHandlers
{
    /**
     * Bind the shared diagnostic reader.
     *
     * @param  OperatorDiagnostics  $diagnostics  Authorized bounded reader.
     *
     * @since  2.0.0
     */
    public function __construct(private OperatorDiagnostics $diagnostics)
    {
    }

    /**
     * Return the same costed document as REST and CLI.
     *
     * @param   ExecutionContext  $context  Authenticated MCP execution context.
     * @param   string            $section  Fixed diagnostic question.
     *
     * @return  array<string, mixed>  Bounded diagnostic document.
     *
     * @since   2.0.0
     */
    public function read(ExecutionContext $context, string $section = 'queues'): array
    {
        return $this->diagnostics->read($context, $section);
    }
}
