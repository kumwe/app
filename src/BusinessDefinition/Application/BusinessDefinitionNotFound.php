<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use RuntimeException;

/**
 * Raised when a business definition the caller named cannot be resolved for the current site.
 *
 * `BusinessDefinitionService` turns every catalog miss into this single name, so delivery code has one
 * thing to catch whether the handle is unknown, belongs to another site, has no draft left to read, or
 * names a version that was never published. Callers may pass a handle or a UUID interchangeably, so the
 * message quotes back exactly what was asked for and says nothing about the stored definition, which makes
 * it safe both to log and to return. The JSON API renders it as `404 Business Definition Not Found`, while
 * the administrator screen catches it to fall back to an unselected page when a stale link names a
 * definition the catalog no longer holds.
 *
 * @since  2.0.0
 */
final class BusinessDefinitionNotFound extends RuntimeException
{
    /**
     * Name the definition, and where relevant the version, that could not be resolved.
     *
     * @param  string  $identifier  Handle or UUID the lookup asked for, quoted back in the message.
     * @param  ?int    $version     Published version that was asked for, or null when the miss was not about
     *         one particular version.
     *
     * @since  2.0.0
     */
    public function __construct(string $identifier, ?int $version = null)
    {
        parent::__construct(sprintf(
            'Business definition %s%s was not found.',
            $identifier,
            $version === null ? '' : ' version ' . $version,
        ));
    }
}
