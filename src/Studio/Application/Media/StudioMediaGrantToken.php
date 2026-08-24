<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadSession;

/**
 * Derives a scoped upload capability without ever persisting its plaintext representation.
 *
 * The upload identifier contains 128 random bits. Binding it to every trusted session coordinate with a
 * dedicated server key gives retries one stable grant while the upload row retains only its SHA-256 digest.
 * The derivation is deliberately isolated from cursor and application keys by container-level HKDF.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaGrantToken
{
    /**
     * Bind the derivation to a dedicated high-entropy application key.
     *
     * @param   string  $key  Binary key of at least 256 bits.
     *
     * @throws  InvalidArgumentException  When the key is too short.
     *
     * @since   2.0.0
     */
    public function __construct(private string $key)
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('The Studio media grant key is too short.');
        }
    }

    /**
     * Derive the exact URL-safe capability for one immutable upload scope.
     *
     * @param   string  $id          Opaque upload identity.
     * @param   string  $actorId     Trusted actor owner.
     * @param   string  $siteId      Trusted site owner.
     * @param   string  $contextKey  Trusted resource-context key.
     * @param   string  $generation  Trusted session generation.
     * @param   string  $expiry      Canonical expiry instant.
     *
     * @return  string  Deterministic unpadded base64url capability.
     *
     * @since   2.0.0
     */
    public function derive(
        string $id,
        string $actorId,
        string $siteId,
        string $contextKey,
        string $generation,
        string $expiry,
    ): string {
        $payload = implode("\0", [$id, $actorId, $siteId, $contextKey, $generation, $expiry]);
        $mac = hash_hmac('sha256', $payload, $this->key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /**
     * Re-derive a persisted session's capability and verify its retained digest before disclosure.
     *
     * @param   StudioMediaUploadSession  $session  Scoped immutable upload snapshot.
     *
     * @return  string  Verified plaintext capability.
     *
     * @throws  StudioMediaPortRejected  When the derivation key no longer matches the persisted grant.
     *
     * @since   2.0.0
     */
    public function restore(StudioMediaUploadSession $session): string
    {
        $token = $this->derive(
            $session->id,
            $session->actorId,
            $session->siteId,
            $session->contextKey,
            $session->generation,
            self::expiry($session),
        );
        if (!hash_equals($session->tokenDigest, hash('sha256', $token))) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
        }

        return $token;
    }

    /**
     * Normalize the expiry into the derivation's stable UTC spelling.
     *
     * @param   StudioMediaUploadSession  $session  Upload whose expiry is bound into the token.
     *
     * @return  string  Microsecond UTC representation.
     *
     * @since   2.0.0
     */
    public static function expiry(StudioMediaUploadSession $session): string
    {
        return $session->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
