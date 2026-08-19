<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * SHA-256 digest that stands for the exact bytes of an extension package.
 *
 * The digest is the package's identity everywhere after download: `AtomicInstallPlan` carries it
 * through the install, the registry stores it against the release row, and signature verification
 * runs over its hexadecimal rendering rather than over the archive, so a verifier never needs the
 * package in memory. Comparison goes through `matches`, which is constant time.
 *
 * @since  2.0.0
 */
final readonly class PackageChecksum implements Stringable
{
    /**
     * Lowercase hexadecimal digest, always 64 characters wide.
     *
     * @var    non-empty-string
     * @since  2.0.0
     */
    private string $sha256;

    /**
     * Hold a digest that has already been proven to be hexadecimal SHA-256.
     *
     * @param  non-empty-string  $sha256  Lowercase hexadecimal digest.
     *
     * @since  2.0.0
     */
    private function __construct(string $sha256)
    {
        $this->sha256 = $sha256;
    }

    /**
     * Adopt a digest supplied as text, such as one read back from the registry or a release feed.
     *
     * Surrounding whitespace and uppercase hexadecimal are normalised; anything that is not exactly
     * 64 hexadecimal characters is refused rather than padded or truncated.
     *
     * @param   string  $hexadecimalDigest  Digest as written, in either case.
     *
     * @return  self  The normalised digest.
     *
     * @throws  InvalidArgumentException  When the value is not a 64-character hexadecimal digest.
     *
     * @since   2.0.0
     */
    public static function sha256(string $hexadecimalDigest): self
    {
        $hexadecimalDigest = strtolower(trim($hexadecimalDigest));

        if (preg_match('/^[0-9a-f]{64}$/D', $hexadecimalDigest) !== 1) {
            throw new InvalidArgumentException('A package checksum must be a hexadecimal SHA-256 digest.');
        }

        return new self($hexadecimalDigest);
    }

    /**
     * Compute the digest of package bytes already held in memory.
     *
     * @param   string  $packageBytes  Complete archive contents to hash.
     *
     * @return  self  Digest of exactly those bytes.
     *
     * @since   2.0.0
     */
    public static function calculate(string $packageBytes): self
    {
        return new self(hash('sha256', $packageBytes));
    }

    /**
     * Check package bytes against this digest in constant time.
     *
     * @param   string  $packageBytes  Complete archive contents to verify.
     *
     * @return  bool  True when those bytes hash to this digest.
     *
     * @since   2.0.0
     */
    public function matches(string $packageBytes): bool
    {
        return hash_equals($this->sha256, hash('sha256', $packageBytes));
    }

    /**
     * Render the digest for storage, logging, and signature verification.
     *
     * @return  non-empty-string  The 64-character lowercase hexadecimal digest.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->sha256;
    }
}
