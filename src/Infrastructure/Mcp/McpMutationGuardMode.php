<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

/**
 * Declares where one MCP tool's replay fence is enforced.
 *
 * `McpCatalogValidator` treats this as an executable binding, not documentation: local mutations must
 * reach `McpMutationGuard::run()` through the `KumweMcpHandlers` call graph, while generated-business
 * mutations must reach the same guard through their `BusinessMcpHandlers` delegate. Read-only tools may
 * declare no mutation route. A catalogue-to-handler drift therefore prevents server construction.
 *
 * @since  2.0.0
 */
enum McpMutationGuardMode: string
{
    /**
     * The tool is read-only and needs no mutation replay fence.
     *
     * @since  2.0.0
     */
    case None = 'none';

    /**
     * The top-level MCP handler or one of its private helpers owns the guard.
     *
     * @since  2.0.0
     */
    case Local = 'local';

    /**
     * The generated-business MCP delegate owns the guard around the typed mutation.
     *
     * @since  2.0.0
     */
    case BusinessDelegate = 'business_delegate';
}
