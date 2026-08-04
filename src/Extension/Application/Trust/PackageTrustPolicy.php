<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;

final readonly class PackageTrustPolicy
{
    /** @var array<string, true> */
    private array $trustedKeyIds;

    /** @param array<mixed> $trustedKeyIds */
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
