<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class PackageChecksum implements Stringable
{
    private function __construct(private string $sha256)
    {
    }

    public static function sha256(string $hexadecimalDigest): self
    {
        $hexadecimalDigest = strtolower(trim($hexadecimalDigest));

        if (preg_match('/^[0-9a-f]{64}$/D', $hexadecimalDigest) !== 1) {
            throw new InvalidArgumentException('A package checksum must be a hexadecimal SHA-256 digest.');
        }

        return new self($hexadecimalDigest);
    }

    public static function calculate(string $packageBytes): self
    {
        return new self(hash('sha256', $packageBytes));
    }

    public function matches(string $packageBytes): bool
    {
        return hash_equals($this->sha256, hash('sha256', $packageBytes));
    }

    public function __toString(): string
    {
        return $this->sha256;
    }
}
