<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

/**
 * Declares where one MCP tool's replay fence is enforced.
 *
 * `McpCatalogValidator` treats this as an executable binding, not documentation: local mutations must
 * reach `McpMutationGuard::run()` through the `KumweMcpHandlers` call graph, while generated-business
 * mutations must reach the same guard through their `BusinessMcpHandlers` delegate, and Studio authoring
 * mutations must hand their `operationId` to the Studio host's replay boundary. Read-only tools may
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

    /**
     * The Studio authoring host's own mutation boundary owns replay, keyed by the tool's `operationId`.
     *
     * Studio authoring mutations already run inside the browser host's atomic claim, transaction, audit and
     * intent-bound replay boundary. Routing them through a second MCP ledger would let that ledger, not the
     * Studio host, decide replay and changed-intent refusals, so MCP and the browser would diverge.
     *
     * @since  2.0.0
     */
    case StudioHostBoundary = 'studio_host_boundary';
}
