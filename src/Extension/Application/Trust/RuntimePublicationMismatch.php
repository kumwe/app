<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use RuntimeException;

/**
 * Raised when the compiled runtime publication disagrees with authoritative extension release metadata.
 *
 * Replica-local publication drift; authoritative extension state remains valid. `TrustStore` keeps this
 * separate from `UntrustedPackage` on purpose: the extension itself still verifies, and only this
 * replica's compiled map is stale or was signed against different release rows. `enforceRuntimeTrust()`
 * therefore rethrows it untouched instead of quarantining the extension, so the caller republishes the
 * runtime map rather than disabling a healthy install.
 *
 * @since  2.0.0
 */
final class RuntimePublicationMismatch extends RuntimeException
{
}
