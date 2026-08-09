<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain\StepUp;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Persistable state of one actor's pending or active TOTP credential.
 *
 * The secret is always an authenticated ciphertext. Its plaintext exists only inside the provider
 * while an enrollment URI is returned or a challenge is checked. The monotonic accepted time-step
 * and version are the two compare-and-swap fences that make a code replay lose under concurrency.
 *
 * @since  2.0.0
 */
final readonly class TotpCredential
{
    /**
     * Validate a credential reconstituted from storage.
     *
     * @param   string              $id                    Canonical credential UUID.
     * @param   string              $subjectId             Canonical actor UUID.
     * @param   string              $encryptedSecret       Authenticated ciphertext envelope.
     * @param   bool                $active                Whether enrollment has been confirmed.
     * @param   DateTimeImmutable   $createdAt             Enrollment creation instant.
     * @param   ?DateTimeImmutable  $enrollmentExpiresAt   Pending enrollment expiry; null once active.
     * @param   ?DateTimeImmutable  $confirmedAt           Confirmation instant; null while pending.
     * @param   ?int                $lastAcceptedTimeStep  Greatest accepted TOTP counter, or null before one.
     * @param   int                 $version               Positive optimistic concurrency version.
     *
     * @throws  InvalidArgumentException  When identities, state, ciphertext, counter, or version disagree.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $subjectId,
        public string $encryptedSecret,
        public bool $active,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $enrollmentExpiresAt,
        public ?DateTimeImmutable $confirmedAt,
        public ?int $lastAcceptedTimeStep,
        public int $version,
    ) {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di';
        if (preg_match($uuid, $id) !== 1 || preg_match($uuid, $subjectId) !== 1) {
            throw new InvalidArgumentException('A TOTP credential requires canonical identifiers.');
        }
        if ($encryptedSecret === '' || strlen($encryptedSecret) > 4096) {
            throw new InvalidArgumentException('A TOTP encrypted secret envelope is invalid.');
        }
        if ($active !== ($confirmedAt !== null) || $active === ($enrollmentExpiresAt !== null)) {
            throw new InvalidArgumentException('A TOTP credential state is inconsistent.');
        }
        if (
            (!$active && $enrollmentExpiresAt <= $createdAt)
            || ($active && $confirmedAt < $createdAt)
        ) {
            throw new InvalidArgumentException('A TOTP credential timeline is inconsistent.');
        }
        if ($lastAcceptedTimeStep !== null && ($lastAcceptedTimeStep < 0 || !$active)) {
            throw new InvalidArgumentException('A TOTP accepted time-step is invalid.');
        }
        if ($version < 1) {
            throw new InvalidArgumentException('A TOTP credential version must be positive.');
        }
    }

    /**
     * Whether this pending enrollment can still be confirmed at an instant.
     *
     * @param   DateTimeImmutable  $now  Instant to compare with its exclusive expiry.
     *
     * @return  bool  True only for a pending, unexpired enrollment.
     *
     * @since   2.0.0
     */
    public function mayConfirmAt(DateTimeImmutable $now): bool
    {
        return !$this->active
            && $this->enrollmentExpiresAt instanceof DateTimeImmutable
            && $now < $this->enrollmentExpiresAt;
    }
}
