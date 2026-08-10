<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application\Custom;

/**
 * Application boundary implemented by one extension-specific business view.
 *
 * Implementations receive only a validated query and its execution context. They must compose typed
 * application services for business reads and must never accept a transport request or query core tables.
 *
 * @since  2.0.0
 */
interface CustomBusinessViewHandler
{
    /**
     * Execute the custom view query and return bounded contract data.
     *
     * @param   CustomBusinessViewQuery  $query  Schema-validated query carrying authenticated context.
     *
     * @return  CustomBusinessViewResult  Bounded data the registry validates against the result schema.
     *
     * @since   2.0.0
     */
    public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult;
}
