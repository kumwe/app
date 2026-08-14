<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use InvalidArgumentException;

/**
 * Where an installation reads upstream signing-key revocations from, and which key vouches for them.
 *
 * Local revocation has always worked: an operator revokes a key and every replica stops trusting it at
 * the next request. What this adds is the upstream half — a signed list a vendor or distributor
 * publishes, which an installation can consume without waiting for its own operator to notice. The
 * verification key is pinned here rather than kept in the trust store on purpose: the store is what the
 * feed revokes, so a feed key that lived inside it would be revocable by the very compromise the feed
 * exists to announce.
 *
 * Leaving `$origin` null disables consumption entirely, which is the default; nothing is fetched and
 * nothing is applied. Supplying an origin without a key, or the other way round, is a configuration
 * error rather than a partially-armed feed.
 *
 * @since  2.0.0
 */
final readonly class RevocationFeedConfiguration
{
    /**
     * Validate the feed origin, its pinned verification key and the staleness budget.
     *
     * @param   ?string  $origin           Absolute `https://` URL or absolute local path the signed list
     *          is read from, or null when the installation consumes no feed.
     * @param   ?string  $publicKeyBase64  Standard base64 of the 32-byte Ed25519 public key every fetched
     *          list must verify against, or null when no feed is configured.
     * @param   int      $maxStaleSeconds  How long a successful fetch stays fresh before the feed is
     *          reported stale; from 3600 to 2592000 seconds.
     *
     * @throws  InvalidArgumentException  When only one of the origin and the key is supplied, the origin
     *          is neither an `https://` URL nor an absolute path, the key is not a base64 Ed25519 public
     *          key, or the staleness budget falls outside the supported window.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ?string $origin = null,
        public ?string $publicKeyBase64 = null,
        public int $maxStaleSeconds = 172_800,
    ) {
        if (($origin === null) !== ($publicKeyBase64 === null)) {
            throw new InvalidArgumentException(
                'EXTENSIONS_REVOCATION_FEED_URL and EXTENSIONS_REVOCATION_FEED_KEY must be set together: '
                . 'a feed with no pinned key would be applied on the word of whoever answered the request.',
            );
        }
        if ($origin !== null && !str_starts_with($origin, 'https://') && !str_starts_with($origin, '/')) {
            throw new InvalidArgumentException(
                'EXTENSIONS_REVOCATION_FEED_URL must be an https:// URL or an absolute local path.',
            );
        }
        if ($publicKeyBase64 !== null) {
            $decoded = base64_decode($publicKeyBase64, true);
            if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException(
                    'EXTENSIONS_REVOCATION_FEED_KEY must be a base64 32-byte Ed25519 public key.',
                );
            }
        }
        if ($maxStaleSeconds < 3_600 || $maxStaleSeconds > 2_592_000) {
            throw new InvalidArgumentException(
                'EXTENSIONS_REVOCATION_FEED_MAX_STALE_SECONDS must be between 3600 and 2592000 seconds.',
            );
        }
    }

    /**
     * Report whether this installation consumes an upstream revocation feed at all.
     *
     * @return  bool  True once both an origin and a pinned verification key are configured.
     *
     * @since   2.0.0
     */
    public function isEnabled(): bool
    {
        return $this->origin !== null && $this->publicKeyBase64 !== null;
    }
}
