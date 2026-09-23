<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\Context\Value\ExecutionContext as HostExecutionContext;
use Kumwe\Extension\Spi\Application\ExecutionContext;

/**
 * Test double of an extension-implemented SDK context that names the coordinates of a host context.
 *
 * It stands for what extension code can build from the host context a custom business query or command
 * carries: the seven public coordinates and nothing else. It is deliberately not the host envelope, so a
 * reader that accepts it proves it resolved the invocation scope rather than an App-issued context.
 *
 * @since  2.0.0
 */
final readonly class CoordinateExecutionContext implements ExecutionContext
{
    /**
     * Wrap the host context whose coordinates this double names.
     *
     * @param  HostExecutionContext  $host  Host context to mirror.
     *
     * @since  2.0.0
     */
    public function __construct(private HostExecutionContext $host)
    {
    }

    /**
     * Return the mirrored site identifier.
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
     * Return the mirrored actor identifier.
     *
     * @return  string  Actor identifier.
     *
     * @since   2.0.0
     */
    public function actorId(): string
    {
        return $this->host->actorId();
    }

    /**
     * Return the mirrored organization identifier.
     *
     * @return  ?string  Organization identifier, or null outside an organization scope.
     *
     * @since   2.0.0
     */
    public function organizationIdentifier(): ?string
    {
        return $this->host->organizationIdentifier();
    }

    /**
     * Return the mirrored workspace identifier.
     *
     * @return  ?string  Workspace identifier, or null outside a workspace scope.
     *
     * @since   2.0.0
     */
    public function workspaceIdentifier(): ?string
    {
        return $this->host->workspaceIdentifier();
    }

    /**
     * Return the mirrored request identifier.
     *
     * @return  string  Request identifier.
     *
     * @since   2.0.0
     */
    public function requestId(): string
    {
        return $this->host->requestId();
    }

    /**
     * Return the mirrored correlation identifier.
     *
     * @return  string  Correlation identifier.
     *
     * @since   2.0.0
     */
    public function correlationId(): string
    {
        return $this->host->correlationId();
    }

    /**
     * Return the mirrored delivery surface.
     *
     * @return  string  Delivery surface value.
     *
     * @since   2.0.0
     */
    public function deliverySurface(): string
    {
        return $this->host->deliverySurface();
    }
}
