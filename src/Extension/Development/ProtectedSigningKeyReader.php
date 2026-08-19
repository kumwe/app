<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Development;

use InvalidArgumentException;
use RuntimeException;

/**
 * Reads an Ed25519 secret key from an owner-only regular file without following links.
 *
 * Files may contain a 32-byte seed or 64-byte secret key in raw, hexadecimal, or strict base64 form.
 * A seed is expanded with libsodium so callers always receive the canonical 64-byte signing key.
 *
 * @since  2.0.0
 */
final readonly class ProtectedSigningKeyReader
{
    /**
     * Read and normalize one protected Ed25519 signing key.
     *
     * @param   string  $path  Canonical absolute owner-only key-file path.
     *
     * @return  non-empty-string  Exactly 64 bytes accepted by `sodium_crypto_sign_detached`.
     *
     * @throws  InvalidArgumentException  When path, owner, mode, encoding, or key length is unsafe.
     * @throws  RuntimeException  When the stable file cannot be locked or read completely.
     *
     * @since   2.0.0
     */
    public function read(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('The signing key file path must be absolute.');
        }
        $canonical = realpath($path);
        $metadata = lstat($path);
        if (
            !is_string($canonical)
            || $canonical !== $path
            || !is_array($metadata)
            || !is_file($path)
            || is_link($path)
            || !is_readable($path)
            || $metadata['size'] < 32
            || $metadata['size'] > 512
        ) {
            throw new InvalidArgumentException('The signing key must be a canonical readable regular file.');
        }
        if (($metadata['mode'] & 0o077) !== 0) {
            throw new InvalidArgumentException('The signing key file must not grant group or other permissions.');
        }
        if (function_exists('posix_geteuid') && $metadata['uid'] !== posix_geteuid()) {
            throw new InvalidArgumentException('The signing key file must be owned by the current user.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false || !flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('The signing key file could not be opened and locked.');
        }
        try {
            $opened = fstat($handle);
            if (
                !is_array($opened)
                || $opened['dev'] !== $metadata['dev']
                || $opened['ino'] !== $metadata['ino']
                || $opened['size'] !== $metadata['size']
                || ($opened['mode'] & 0o077) !== 0
                || (function_exists('posix_geteuid') && $opened['uid'] !== posix_geteuid())
            ) {
                throw new RuntimeException('The signing key file changed while it was opened.');
            }
            $encoded = stream_get_contents($handle, 513);
            $after = fstat($handle);
            if (
                !is_string($encoded)
                || strlen($encoded) !== $metadata['size']
                || !is_array($after)
                || $after['dev'] !== $opened['dev']
                || $after['ino'] !== $opened['ino']
                || $after['size'] !== $opened['size']
                || $after['mtime'] !== $opened['mtime']
                || $after['ctime'] !== $opened['ctime']
                || ($after['mode'] & 0o077) !== 0
            ) {
                throw new RuntimeException('The signing key file changed while it was read.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $key = $this->decode($encoded);
        sodium_memzero($encoded);
        if (strlen($key) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $keyPair = sodium_crypto_sign_seed_keypair($key);
            sodium_memzero($key);
            $secret = sodium_crypto_sign_secretkey($keyPair);
            sodium_memzero($keyPair);

            return $secret;
        }
        if (strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            sodium_memzero($key);
            throw new InvalidArgumentException('The signing key must contain a 32-byte seed or 64-byte secret key.');
        }

        return $key;
    }

    /**
     * Decode the supported protected-file representations without accepting ambiguous whitespace.
     *
     * @param   string  $encoded  Raw protected-file bytes.
     *
     * @return  string  Candidate raw seed or secret-key bytes.
     *
     * @since   2.0.0
     */
    private function decode(string $encoded): string
    {
        $text = trim($encoded);
        if (preg_match('/^(?:[0-9a-fA-F]{64}|[0-9a-fA-F]{128})$/D', $text) === 1) {
            $decoded = hex2bin($text);

            return is_string($decoded) ? $decoded : '';
        }
        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $text) === 1) {
            $decoded = base64_decode($text, true);
            if (
                is_string($decoded)
                && in_array(strlen($decoded), [32, 64], true)
                && hash_equals(base64_encode($decoded), $text)
            ) {
                return $decoded;
            }
        }

        if (strlen($encoded) === 32 || strlen($encoded) === 64) {
            return $encoded;
        }

        return '';
    }
}
