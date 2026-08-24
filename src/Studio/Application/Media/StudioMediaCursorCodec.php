<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use JsonException;

/**
 * HMAC-authenticated opaque cursor scoped to one site and exact normalized media query.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaCursorCodec
{
    /**
     * Retain a dedicated derived signing key.
     *
     * @param  string  $key  At least 32 bytes of cursor-only key material.
     *
     * @since  2.0.0
     */
    public function __construct(private string $key)
    {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('The Studio media cursor key is too short.');
        }
    }

    /**
     * Mint a compact opaque cursor for a next page.
     *
     * @param   string  $siteId       Trusted site identity.
     * @param   string  $queryDigest  Digest of normalized filters.
     * @param   int     $offset       Next zero-based offset.
     *
     * @return  string  URL-safe authenticated cursor.
     *
     * @since   2.0.0
     */
    public function encode(string $siteId, string $queryDigest, int $offset): string
    {
        $payload = json_encode(
            ['v' => 1, 's' => hash('sha256', $siteId), 'q' => $queryDigest, 'o' => $offset],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $encoded = self::base64Url($payload);

        return $encoded . '.' . self::base64Url(hash_hmac('sha256', $encoded, $this->key, true));
    }

    /**
     * Verify and decode a cursor only for the same trusted site and normalized query.
     *
     * @param   string  $cursor       Untrusted opaque cursor.
     * @param   string  $siteId       Trusted site identity.
     * @param   string  $queryDigest  Digest of normalized filters.
     *
     * @return  int  Verified zero-based offset.
     *
     * @throws  StudioMediaPortRejected  When the cursor is malformed, forged or rebound.
     *
     * @since   2.0.0
     */
    public function decode(string $cursor, string $siteId, string $queryDigest): int
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || strlen($cursor) > 1000) {
            throw new StudioMediaPortRejected('invalid-request', 'studio.media/cursor-invalid');
        }
        [$encoded, $signature] = $parts;
        $decodedSignature = self::decodeBase64Url($signature);
        if (
            $decodedSignature === null
            || !hash_equals(hash_hmac('sha256', $encoded, $this->key, true), $decodedSignature)
        ) {
            throw new StudioMediaPortRejected('invalid-request', 'studio.media/cursor-invalid');
        }
        $payload = self::decodeBase64Url($encoded);
        try {
            $document = is_string($payload) ? json_decode($payload, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $document = null;
        }
        if (
            !is_array($document)
            || array_keys($document) !== ['v', 's', 'q', 'o']
            || ($document['v'] ?? null) !== 1
            || !is_string($document['s'] ?? null)
            || !hash_equals(hash('sha256', $siteId), $document['s'])
            || !is_string($document['q'] ?? null)
            || !hash_equals($queryDigest, $document['q'])
            || !is_int($document['o'] ?? null)
            || $document['o'] < 0
        ) {
            throw new StudioMediaPortRejected('invalid-request', 'studio.media/cursor-invalid');
        }

        return $document['o'];
    }

    /**
     * Encode bytes as URL-safe unpadded base64.
     *
     * @param   string  $value  Raw bytes.
     *
     * @return  string  URL-safe text.
     *
     * @since   2.0.0
     */
    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Strictly decode URL-safe unpadded base64.
     *
     * @param   string  $value  Candidate URL-safe text.
     *
     * @return  string|null  Decoded bytes or null.
     *
     * @since   2.0.0
     */
    private static function decodeBase64Url(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return is_string($decoded) ? $decoded : null;
    }
}
