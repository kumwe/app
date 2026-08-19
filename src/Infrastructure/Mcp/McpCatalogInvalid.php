<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use LogicException;

/**
 * Signals that the published MCP surface contradicts the rules every entry in it must satisfy.
 *
 * `McpCatalogValidator` raises this, and `KumweMcpServerFactory` lets it propagate rather than
 * registering a surface it could not prove, so an incoherent catalogue is a boot failure with a named
 * cause instead of a tool a client discovers and misuses. It is a `LogicException` deliberately: every
 * condition it reports is a defect in the release's own declaration, never something a caller did, and
 * no runtime input can provoke it.
 *
 * The message names every violation found in one pass, so a change that breaks several entries is
 * reported once with the whole list rather than one entry at a time.
 *
 * @since  2.0.0
 */
final class McpCatalogInvalid extends LogicException
{
}
