<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

/**
 * The separate reasons Kumwe holds authenticated-encryption key material, one key ring each.
 *
 * Two very different things are sealed with the same construction: business-record secret fields, which
 * outlive every process that wrote them, and mutation-plan tokens, which are handed to a browser and
 * expire within minutes. Sharing one key made the two lifecycles one lifecycle — rotating for the sake of
 * a five-minute token would have rewritten every stored envelope, and moving record keys into a managed
 * KMS would have dragged plan tokens along with them. Each case here names its own derivation label and
 * its own default key identifier, so the rings are derived from different bytes and their envelopes are
 * refused by identifier if one ever reaches the other. The associated data already separated the two
 * domains; this separates the keys that authenticate it.
 *
 * @since  2.0.0
 */
enum SecretKeyPurpose: string
{
    /**
     * Durable `core.secret` record fields, sealed once and expected to open years later.
     *
     * @since  2.0.0
     */
    case Record = 'record';

    /**
     * Short-lived opaque business mutation-plan tokens, which expire long before a rotation matters.
     *
     * @since  2.0.0
     */
    case MutationPlan = 'mutation-plan';

    /**
     * Name the HKDF info string this purpose derives its key material under.
     *
     * The label is frozen: changing one of these strings changes every key derived from the same
     * configured secret, which would strand the envelopes already sealed under it. A new derivation gets
     * a new label and a new key identifier instead, and the previous key stays in the ring.
     *
     * @return  string  Purpose-specific derivation label, never shared between two purposes.
     *
     * @since   2.0.0
     */
    public function derivationLabel(): string
    {
        return match ($this) {
            self::Record => 'kumwe:business-record:encryption:v2',
            self::MutationPlan => 'kumwe:business-mutation-plan:encryption:v1',
        };
    }

    /**
     * Name the key identifier used when a deployment configures no identifier of its own.
     *
     * @return  string  Default identifier stamped into envelopes this purpose seals.
     *
     * @since   2.0.0
     */
    public function defaultKeyId(): string
    {
        return match ($this) {
            self::Record => 'record-encryption-v1',
            self::MutationPlan => 'mutation-plan-v1',
        };
    }
}
