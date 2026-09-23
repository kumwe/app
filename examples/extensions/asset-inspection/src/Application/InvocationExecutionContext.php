<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Application;

use Kumwe\Context\Value\ExecutionContext as HostExecutionContext;
use Kumwe\Extension\Spi\Application\ExecutionContext;

/**
 * Presents the host context a custom view or action carries as the SDK context its record reads name.
 *
 * A custom business query or command carries the host execution context itself, while the SDK
 * business-record reader takes the SDK context interface. This value names the seven coordinates of that
 * host context and nothing else; the host resolves it to the invocation it issued the context for, and only
 * while that invocation runs. It grants no authority of its own.
 *
 * @since  2.0.0
 */
final readonly class InvocationExecutionContext implements ExecutionContext
{
    /**
     * Wrap the host context of the running invocation.
     *
     * @param  HostExecutionContext  $host  Context the custom query or command carries.
     *
     * @since  2.0.0
     */
    private function __construct(private HostExecutionContext $host)
    {
    }

    /**
     * Name the host context of the running invocation for an SDK request.
     *
     * @param   HostExecutionContext  $host  Context the custom query or command carries.
     *
     * @return  self  SDK context naming the same coordinates.
     *
     * @since   2.0.0
     */
    public static function of(HostExecutionContext $host): self
    {
        return new self($host);
    }

    /**
     * Return the site this invocation is scoped to.
     *
     * @return  string  Canonical site identifier.
     *
     * @since   2.0.0
     */
    public function siteIdentifier(): string
    {
        return $this->host->siteIdentifier();
    }

    /**
     * Return the acting subject.
     *
     * @return  string  Actor identifier the host issued.
     *
     * @since   2.0.0
     */
    public function actorId(): string
    {
        return $this->host->actorId();
    }

    /**
     * Return the organization scope, when the invocation carries one.
     *
     * @return  ?string  Canonical organization identifier, or null outside an organization scope.
     *
     * @since   2.0.0
     */
    public function organizationIdentifier(): ?string
    {
        return $this->host->organizationIdentifier();
    }

    /**
     * Return the workspace scope, when the invocation carries one.
     *
     * @return  ?string  Canonical workspace identifier, or null outside a workspace scope.
     *
     * @since   2.0.0
     */
    public function workspaceIdentifier(): ?string
    {
        return $this->host->workspaceIdentifier();
    }

    /**
     * Return the request identity of the invocation.
     *
     * @return  string  Distinct per operation.
     *
     * @since   2.0.0
     */
    public function requestId(): string
    {
        return $this->host->requestId();
    }

    /**
     * Return the correlation identity of the invocation.
     *
     * @return  string  Carried unchanged into nested operations.
     *
     * @since   2.0.0
     */
    public function correlationId(): string
    {
        return $this->host->correlationId();
    }

    /**
     * Return the surface the invocation was authenticated on.
     *
     * @return  string  Backing value of the authenticated surface.
     *
     * @since   2.0.0
     */
    public function deliverySurface(): string
    {
        return $this->host->deliverySurface();
    }
}
