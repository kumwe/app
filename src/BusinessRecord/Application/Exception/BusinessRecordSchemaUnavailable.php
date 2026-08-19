<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

/**
 * Signals that the installed physical schema cannot serve the record operation that was asked for.
 *
 * This is the record layer's answer whenever storage and the definition it was compiled from
 * disagree: an installation that is absent, not active, or registered to another site or owner; an
 * installed checksum that no longer matches the published definition version; a request pinned to a
 * definition version newer than the one installed; or a stored row whose column is missing from the
 * blueprint or holds a value the blueprint does not describe. It is an operator-facing condition
 * rather than a transient one: unlike `BusinessRecordTemporarilyUnavailable` there is nothing to
 * retry, because the installed schema has to change before the same request can succeed.
 *
 * @since  2.0.0
 */
final class BusinessRecordSchemaUnavailable extends BusinessRecordException
{
    /**
     * Build the signal, optionally stating which disagreement was found.
     *
     * @param  string  $reason  Operator-facing sentence naming the specific disagreement; the default
     *         serves callers that only know the installation is unusable.
     *
     * @since  2.0.0
     */
    public function __construct(string $reason = 'The installed business schema is unavailable.')
    {
        parent::__construct('business_record.schema_unavailable', $reason);
    }
}
