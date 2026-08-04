<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;

interface PackageSignatureVerifier
{
    public function verify(PackageChecksum $checksum, PackageSignature $signature): bool;
}
