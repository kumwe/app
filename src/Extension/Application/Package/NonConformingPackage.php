<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

use DomainException;

/**
 * Refusal raised when App admission policy rejects neutral SDK package findings.
 *
 * The SDK reports facts; this host exception records that App policy judged those facts unfit for
 * installation. A corrected package is required, so the failure is a domain refusal rather than a
 * transient transport error.
 *
 * @since  2.0.0
 */
final class NonConformingPackage extends DomainException
{
}
