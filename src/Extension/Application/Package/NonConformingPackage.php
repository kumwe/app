<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

use DomainException;

/**
 * Refusal raised when install-time admission finds a package it will not unpack.
 *
 * It sits beside `UnsafePackage` and `UntrustedPackage` as the third admission refusal and covers what
 * neither of those two look at: the content of the packaged code and the attestation documents the
 * package carries about itself. Like them it is thrown rather than returned, so no caller can proceed
 * by forgetting to inspect a verdict, and it extends `DomainException` because the archive was read
 * successfully and judged unfit — the answer is a corrected package, never a retry.
 *
 * @since  2.0.0
 */
final class NonConformingPackage extends DomainException
{
}
