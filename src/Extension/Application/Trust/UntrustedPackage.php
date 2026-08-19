<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Trust;

use DomainException;

/**
 * Raised when an extension package cannot be shown to come from a key this installation trusts.
 *
 * One name covers every way trust fails, so install, activation and per-request enforcement all have a
 * single thing to catch: an unsigned package where unsigned packages are disabled, a signing key that is
 * revoked, expired or outside its namespace, a signature that does not verify over the package checksum,
 * a release record that is missing or not in the `verified` state, and deployed bytes that no longer
 * digest to what was signed. `TrustStore::enforceRuntimeTrust()` treats it as grounds to quarantine the
 * extension, which is what separates it from `RuntimePublicationMismatch`.
 *
 * @since  2.0.0
 */
final class UntrustedPackage extends DomainException
{
}
