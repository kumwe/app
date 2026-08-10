<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

use InvalidArgumentException;
use RuntimeException;

/**
 * Signs an inspected package digest with a protected Ed25519 secret key and writes a public sidecar.
 *
 * @since  2.0.0
 */
final readonly class PackageSigner
{
    /**
     * Bind signing to protected key loading and production package inspection.
     *
     * @param  ProtectedSigningKeyReader  $keys       Reader enforcing owner-only signing-key files.
     * @param  PackageInspector           $inspector  Package safety and checksum boundary.
     *
     * @since  2.0.0
     */
    public function __construct(private ProtectedSigningKeyReader $keys, private PackageInspector $inspector)
    {
    }

    /**
     * Sign the lowercase hexadecimal package digest expected by Kumwe's trust verifier.
     *
     * @param   string  $archiveFile  Canonical absolute installable ZIP path.
     * @param   string  $keyId        Trust-store identifier of the matching public key.
     * @param   string  $keyFile      Canonical absolute protected secret-key path.
     *
     * @return  SignatureDocument  Public detached signature sidecar data.
     *
     * @throws  InvalidArgumentException  When package, key identifier, or key file is invalid.
     *
     * @since   2.0.0
     */
    public function sign(string $archiveFile, string $keyId, string $keyFile): SignatureDocument
    {
        $inspection = $this->inspector->inspect($archiveFile);
        $secretKey = $this->keys->read($keyFile);
        try {
            $signature = sodium_crypto_sign_detached((string) $inspection->checksum, $secretKey);
        } finally {
            sodium_memzero($secretKey);
        }

        $document = new SignatureDocument(
            $keyId,
            (string) $inspection->checksum,
            base64_encode($signature),
        );
        $confirmed = $this->inspector->inspect($archiveFile);
        if (!hash_equals($document->packageSha256, (string) $confirmed->checksum)) {
            throw new RuntimeException('The extension package changed while its signature was created.');
        }

        return $document;
    }

    /**
     * Publish a signature sidecar without replacing an existing path.
     *
     * @param   SignatureDocument  $document    Validated detached signature document.
     * @param   string             $outputFile  Canonical absolute sidecar path.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the output path is relative, non-canonical, or already exists.
     * @throws  RuntimeException  When private staging, protection, or atomic publication fails.
     *
     * @since   2.0.0
     */
    public function write(SignatureDocument $document, string $outputFile): void
    {
        if (!str_starts_with($outputFile, '/') || file_exists($outputFile) || is_link($outputFile)) {
            throw new InvalidArgumentException('The extension signature output must be a new absolute path.');
        }
        $parent = realpath(dirname($outputFile));
        if (!is_string($parent) || is_link(dirname($outputFile)) || !is_writable($parent)) {
            throw new InvalidArgumentException('The extension signature output parent is unavailable or unsafe.');
        }
        $output = $parent . '/' . basename($outputFile);
        if ($output !== $outputFile) {
            throw new InvalidArgumentException('The extension signature output path must be canonical.');
        }
        $temporary = $parent . '/.' . basename($output) . '.kumwe-signature-' . bin2hex(random_bytes(12));
        $json = $document->toJson();
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            if (is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException('The extension signature sidecar could not be written completely.');
        }
        if (!chmod($temporary, 0644)) {
            @unlink($temporary);
            throw new RuntimeException('The extension signature sidecar could not be protected.');
        }
        if (!link($temporary, $output)) {
            @unlink($temporary);
            throw new RuntimeException('The extension signature sidecar output was claimed before publication.');
        }
        @unlink($temporary);
    }
}
