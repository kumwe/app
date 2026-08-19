<?php

declare(strict_types=1);

namespace Kumwe\App\Kernel\Configuration;

use InvalidArgumentException;

/**
 * The dedicated key material business-record secret fields are sealed with.
 *
 * Record envelopes used to be sealed with a key derived from `APP_SECRET`, which tied the lifetime of
 * every stored secret to the lifetime of the session and token secret: rotating one after a compromise
 * made the other unreadable, so the honest advice was to rotate neither. These settings break that tie.
 * A deployment supplies its own record secret, keeps the secrets it has retired so far, and — while an
 * `APP_SECRET` rotation is in flight — names the previous application secret so the envelopes already
 * written under it keep opening.
 *
 * Every field is optional. An installation that configures none of them keeps exactly the behaviour it
 * had: the `application-secret-v1` key stays active and nothing has to be re-encrypted. Configuring
 * `$activeKey` makes that key retired instead of active, at which point `business:rekey-secrets` moves
 * stored envelopes across at the operator's pace.
 *
 * @since  2.0.0
 */
final readonly class RecordEncryptionConfiguration
{
    /**
     * Capture and validate the record-encryption settings the deployment supplied.
     *
     * Byte-length and identifier-shape checks live in `ConfiguredSecretKeyRings`, which is what actually
     * derives the ring; what is enforced here is coherence between the settings, so a half-configured
     * rotation fails at boot instead of at the first secret write.
     *
     * @param   ?string                $activeKeyId   Identifier stamped into newly sealed envelopes, or
     *          null for the built-in default; it may only be set alongside `$activeKey`.
     * @param   ?string                $activeKey     Dedicated record-encryption secret, or null to keep
     *          the `APP_SECRET`-derived key active.
     * @param   array<string, string>  $previousKeys  Retired dedicated secrets by identifier, kept so
     *          envelopes from earlier rotations still open while re-encryption runs.
     * @param   ?string                $legacySecret  Previous `APP_SECRET`, supplied so the
     *          `application-secret-v1` key survives an application-secret rotation; null derives it from
     *          the current `APP_SECRET` exactly as before.
     *
     * @throws  InvalidArgumentException  When an identifier is set without its key, or a retired
     *          identifier repeats the active one. No message quotes secret material.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ?string $activeKeyId = null,
        public ?string $activeKey = null,
        public array $previousKeys = [],
        public ?string $legacySecret = null,
    ) {
        if ($activeKeyId !== null && $activeKey === null) {
            throw new InvalidArgumentException('RECORD_ENCRYPTION_KEY_ID requires RECORD_ENCRYPTION_KEY.');
        }
        if ($activeKeyId !== null && array_key_exists($activeKeyId, $previousKeys)) {
            throw new InvalidArgumentException('RECORD_ENCRYPTION_PREVIOUS_KEYS repeats the active key identifier.');
        }
    }
}
