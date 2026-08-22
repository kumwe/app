<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

/**
 * Closed vocabulary for MCP tools whose capability cannot be one catalogue literal.
 *
 * A literal capability remains a string in `McpCapabilityCatalog`. These cases cover the deliberately
 * dynamic exceptions: authenticated discovery, content transitions selected from live workflow state,
 * custom business views whose signed kind decides the operation, and mutation planning whose requested
 * operation decides the additional capability. `McpCatalogValidator` proves the named live handler takes
 * the corresponding enforcement route before the server registers it.
 *
 * @since  2.0.0
 */
enum McpDynamicCapabilityResolver: string
{
    /**
     * Authentication alone admits discovery of the public surface metadata.
     *
     * @since  2.0.0
     */
    case Authenticated = 'authenticated';

    /**
     * Live content workflow state resolves the exact transition capability.
     *
     * @since  2.0.0
     */
    case ContentTransition = 'content_transition';

    /**
     * The declared custom-view kind resolves its business-surface capability.
     *
     * @since  2.0.0
     */
    case BusinessView = 'business_view';

    /**
     * The closed requested mutation operation resolves the capability a plan must bind.
     *
     * @since  2.0.0
     */
    case BusinessMutationPlan = 'business_mutation_plan';
}
