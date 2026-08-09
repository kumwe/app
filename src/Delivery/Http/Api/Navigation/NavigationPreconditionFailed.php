<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use DomainException;

/**
 * Signals that a navigation write carried no usable `If-Match` value for the record it addresses.
 *
 * Whether a precondition still holds cannot be settled from the headers alone: the menu or item has to
 * be loaded and its version turned into an entity tag first, which is where
 * `NavigationApiRequest::expectedVersion()` raises this — for a tag naming an older version, and for a
 * mutating request that reached a navigation route with no precondition attached at all.
 * `NavigationApiResponder` maps it to 412 alongside `NavigationVersionConflict`, so a stale
 * administrator screen and a writer who committed first reach the client as one status with one
 * remedy: re-read the record and retry against the tag it carries now.
 *
 * @since  2.0.0
 */
final class NavigationPreconditionFailed extends DomainException
{
    /**
     * Compose the fixed operator-facing message this failure always carries.
     *
     * The responder publishes the message verbatim as the problem document's `detail`, so it names the
     * cause without quoting either the tag that was sent or the version that was stored.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The supplied navigation ETag does not match the current version.');
    }
}
