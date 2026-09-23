<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application\Custom;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\ExecutionContext as ExtensionContext;
use LogicException;

/**
 * Names the host-issued execution context of the custom business invocation currently executing.
 *
 * `kumwe/business-surface-contract` hands a custom view or action handler the host execution context
 * itself, while the SDK business-record reader port accepts only the SDK context interface and the host
 * honours no context it did not issue. This scope is how the host issues one for exactly the duration of a
 * custom invocation without the handler holding an App type: the dispatcher enters the scope with the
 * query's or command's host context before extension code runs and leaves it afterwards, and the reader
 * resolves a request whose context names the same seven coordinates to that host context. Authority never
 * comes from the handler's object: it comes from the host context the dispatcher registered, so a context
 * an extension presents outside its own invocation, or naming other coordinates, resolves to nothing and
 * is refused by the reader as before.
 *
 * @since  2.0.0
 */
final class CustomBusinessInvocationScope
{
    /**
     * Host contexts of the invocations currently executing, innermost last.
     *
     * @var    list<ExecutionContext>
     * @since  2.0.0
     */
    private array $active = [];

    /**
     * Register the host context of a custom business invocation that is about to run.
     *
     * The caller leaves the scope in a `finally` block, so a failing handler cannot leave its context behind.
     *
     * @param   ExecutionContext  $context  Host-issued context the invocation executes under.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function enter(ExecutionContext $context): void
    {
        $this->active[] = $context;
    }

    /**
     * Forget the innermost invocation's host context once its handler has returned or thrown.
     *
     * @return  void
     *
     * @throws  LogicException  When no invocation is executing.
     *
     * @since   2.0.0
     */
    public function leave(): void
    {
        if ($this->active === []) {
            throw new LogicException('No custom business invocation is executing.');
        }
        array_pop($this->active);
    }

    /**
     * Resolve the active invocation's host context for an SDK context naming its coordinates.
     *
     * @param   ExtensionContext  $context  Context an extension placed in an SDK request.
     *
     * @return  ?ExecutionContext  The active invocation's host context when every coordinate matches,
     *          otherwise null.
     *
     * @since   2.0.0
     */
    public function hostFor(ExtensionContext $context): ?ExecutionContext
    {
        $innermost = array_key_last($this->active);
        if ($innermost === null) {
            return null;
        }
        $host = $this->active[$innermost];
        $matches = $context->siteIdentifier() === $host->siteIdentifier()
            && $context->actorId() === $host->actorId()
            && $context->organizationIdentifier() === $host->organizationIdentifier()
            && $context->workspaceIdentifier() === $host->workspaceIdentifier()
            && $context->requestId() === $host->requestId()
            && $context->correlationId() === $host->correlationId()
            && $context->deliverySurface() === $host->deliverySurface();

        return $matches ? $host : null;
    }
}
