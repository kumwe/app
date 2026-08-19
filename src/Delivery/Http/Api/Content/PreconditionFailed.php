<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Content;

use RuntimeException;

/**
 * Signals that a content request carried no usable `If-Match` value for the entry it addresses.
 *
 * Whether a precondition still holds cannot be decided from the headers alone: the record has to be
 * loaded and its version turned into an entity tag first, which is where
 * `ContentApiRequest::expectedVersion()` raises this — for a tag naming a different version, and for a
 * mutating request that reached a content route with no precondition attached at all.
 * `ContentApiResponder` maps it to 412 alongside `VersionConflict`, so both ways of losing a race — a
 * stale editor form and a writer who committed first — reach the client as one status with one remedy:
 * re-read the entry and retry against the tag it carries now.
 *
 * @since  2.0.0
 */
final class PreconditionFailed extends RuntimeException
{
    /**
     * Compose the fixed operator-facing message this failure always carries.
     *
     * Neither the tag that was sent nor the version that was stored is quoted, so nothing taken from
     * the request reaches the problem document built out of this message.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The supplied If-Match value does not identify the current content version.');
    }
}
