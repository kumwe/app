<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Trust;

use Kumwe\Extension\Package\PackageSignatureVerifier;
use InvalidArgumentException;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignature;

/**
 * Signing policy for an installation whose set of trusted keys is fixed at wiring time.
 *
 * It pairs a static allow-list of key IDs with a `PackageSignatureVerifier`, and admits a package only
 * when both agree: the key that signed it is on the list, and the signature verifies over the package
 * digest. Unsigned packages are refused unless the installation has opted in and the package came
 * from a local upload, which keeps a development convenience from ever applying to a remote source.
 *
 * This is the simple counterpart to `TrustStore`, which reads its keys from the database and adds
 * expiry, namespace constraints, revocation, and auditing. Reach for this policy where those
 * lifecycle concerns do not exist and a configured list of keys is the whole trust story.
 *
 * @since  2.0.0
 */
final readonly class PackageTrustPolicy
{
    /**
     * Trusted key IDs held as a set, so membership is a keyed lookup rather than a scan.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private array $trustedKeyIds;

    /**
     * Fix the set of signing keys this installation will accept.
     *
     * @param   PackageSignatureVerifier  $verifier                    Cryptographic check run on a trusted key.
     * @param   array<mixed>              $trustedKeyIds               Key IDs this installation accepts.
     * @param   bool                      $allowUnsignedLocalPackages  Whether unsigned local uploads may pass.
     *
     * @throws  InvalidArgumentException  When the key IDs are not a list, or one is not a stable identifier.
     *
     * @since   2.0.0
     */
    public function __construct(
        private PackageSignatureVerifier $verifier,
        array $trustedKeyIds,
        private bool $allowUnsignedLocalPackages = false,
    ) {
        $keys = [];

        if (!array_is_list($trustedKeyIds)) {
            throw new InvalidArgumentException('Trusted signing key IDs must be a list.');
        }

        foreach ($trustedKeyIds as $keyId) {
            if (!is_string($keyId) || preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1) {
                throw new InvalidArgumentException('Every trusted signing key ID must be a stable identifier.');
            }

            $keys[$keyId] = true;
        }

        $this->trustedKeyIds = $keys;
    }

    /**
     * Refuse a package that this installation's signing policy does not admit.
     *
     * The unsigned path is the narrow one: a package with no signature passes only when it arrived as
     * a local upload and unsigned local packages are enabled, so a remote source can never benefit
     * from that setting. Everything else must name a key on the trusted list and verify against it.
     *
     * @param   PackageChecksum    $checksum         Digest of the package being installed.
     * @param   ?PackageSignature  $signature        Signature offered with the package, or null if unsigned.
     * @param   bool               $fromLocalUpload  Whether the package arrived as a local upload.
     *
     * @return  void
     *
     * @throws  UntrustedPackage  When the package carries no acceptable signature from a trusted key.
     *
     * @since   2.0.0
     */
    public function assertTrusted(
        PackageChecksum $checksum,
        ?PackageSignature $signature,
        bool $fromLocalUpload,
    ): void {
        if ($signature === null) {
            if ($fromLocalUpload && $this->allowUnsignedLocalPackages) {
                return;
            }

            throw new UntrustedPackage('An extension package must have a trusted signature.');
        }

        if (!isset($this->trustedKeyIds[$signature->keyId()])) {
            throw new UntrustedPackage('The extension package signing key is not trusted.');
        }

        if (!$this->verifier->verify($checksum, $signature)) {
            throw new UntrustedPackage('The extension package signature is invalid.');
        }
    }
}
