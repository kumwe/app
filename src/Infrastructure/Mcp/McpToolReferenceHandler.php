<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Throwable;

/**
 * Decorates the SDK's argument-binding reference handler with Kumwe's tool refusal contract.
 *
 * Only tool references are mapped. Resources and prompts retain the SDK's protocol-level failure behavior,
 * and an unrecognized tool failure is rethrown so the SDK's `CallToolHandler` logs it and returns its generic
 * internal error. The delegate still owns reflection and named argument conversion, preserving SDK v0.7.1
 * behavior instead of reimplementing it in application code.
 *
 * @since  2.0.0
 */
final readonly class McpToolReferenceHandler implements ReferenceHandlerInterface
{
    /**
     * Bind the untouched SDK delegate and the closed application refusal mapper.
     *
     * @param  ReferenceHandlerInterface  $delegate  Official SDK reference executor.
     * @param  McpToolErrorMapper         $errors    Expected application refusal mapper.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ReferenceHandlerInterface $delegate,
        private McpToolErrorMapper $errors,
    ) {
    }

    /**
     * Execute one registered element, mapping only expected failures from tools.
     *
     * @param   ElementReference      $reference  SDK registration and original callable.
     * @param   array<string, mixed>  $arguments  Protocol arguments plus SDK request/session context.
     *
     * @return  mixed  Delegate success or an explicit `CallToolResult::error()` refusal.
     *
     * @throws  Throwable  For resources, prompts and unexpected tool defects, handled by the SDK boundary.
     *
     * @since   2.0.0
     */
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        try {
            return $this->delegate->handle($reference, $arguments);
        } catch (Throwable $exception) {
            if ($reference instanceof ToolReference) {
                $result = $this->errors->map($exception);
                if ($result !== null) {
                    return $result;
                }
            }

            throw $exception;
        }
    }
}
