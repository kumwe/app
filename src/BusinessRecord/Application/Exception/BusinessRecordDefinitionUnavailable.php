<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

/**
 * Raised when the business definition an operation names cannot be used for it right now.
 *
 * This is the definition-side half of the pair `BusinessRecordDefinitionResolver` reports; the other
 * half, `BusinessRecordSchemaUnavailable`, covers the physical schema. It means the catalog entry is
 * the obstacle: no definition matches the identifier on this site, the owner supplying it — an
 * extension, typically — is flagged inactive, or the version being asked for is not published or has
 * been rejected, whether that is the installed version or an older one a stored row pins itself to.
 * `DoctrineBusinessRecordMutationFence` raises the same failure while taking the mutation lock, so a
 * definition withdrawn mid-command reads the same as one that was never there.
 *
 * Callers should treat it as "not usable now" rather than "never existed": a reactivated owner or a
 * newly published version makes the identical request succeed.
 *
 * @since  2.0.0
 */
final class BusinessRecordDefinitionUnavailable extends BusinessRecordException
{
    /**
     * Report an unusable definition under the `business_record.definition_unavailable` code.
     *
     * @param  string  $reason  Operator-facing sentence naming which check failed; the unqualified
     *         default is what a missing catalog entry or an unpublished installed version reports.
     *
     * @since  2.0.0
     */
    public function __construct(string $reason = 'The business definition is unavailable.')
    {
        parent::__construct('business_record.definition_unavailable', $reason);
    }
}
