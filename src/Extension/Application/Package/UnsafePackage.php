<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

use DomainException;

/**
 * Refusal raised when an extension archive fails the checks that precede extraction.
 *
 * `PackageSafetyPolicy` throws this instead of returning a verdict, so no caller can extract an
 * archive by forgetting to read a result. It reports a judgement about the package rather than a
 * failure to read it — the archive was inspected successfully and found unfit — which is why it
 * extends `DomainException`, and why the answer to it is a different package rather than a retry.
 *
 * @since  2.0.0
 */
final class UnsafePackage extends DomainException
{
}
