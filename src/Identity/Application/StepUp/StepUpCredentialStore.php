<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\StepUp;

use DateTimeImmutable;
use Kumwe\CMS\Identity\Domain\StepUp\TotpCredential;

/**
 * Persistence port for encrypted TOTP credentials and single-use recovery digests.
 *
 * Every accepting method is a compare-and-swap operation. Implementations must return false, never
 * widen the update, when another request advanced the credential version first.
 *
 * @since  2.0.0
 */
interface StepUpCredentialStore
{
    /**
     * Replace the subject's pending enrollment without disturbing an active credential.
     *
     * @param   TotpCredential  $credential  Pending credential carrying an encrypted secret.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the subject already has an active credential.
     *
     * @since   2.0.0
     */
    public function replacePending(TotpCredential $credential): void;

    /**
     * Find the exact pending enrollment for a subject.
     *
     * @param   string  $credentialId  Enrollment UUID.
     * @param   string  $subjectId     Authenticated actor UUID.
     *
     * @return  ?TotpCredential  Pending record, or null without distinguishing why it is unavailable.
     *
     * @since   2.0.0
     */
    public function pending(string $credentialId, string $subjectId): ?TotpCredential;

    /**
     * Find the subject's active credential.
     *
     * @param   string  $subjectId  Authenticated actor UUID.
     *
     * @return  ?TotpCredential  Active record or null when none is enrolled.
     *
     * @since   2.0.0
     */
    public function active(string $subjectId): ?TotpCredential;

    /**
     * Confirm a pending enrollment and install its recovery-code digests atomically.
     *
     * @param   string             $credentialId       Enrollment UUID.
     * @param   string             $subjectId          Authenticated actor UUID.
     * @param   int                $expectedVersion    Version read before verification.
     * @param   int                $acceptedTimeStep   TOTP counter proven during confirmation.
     * @param   list<string>       $recoveryDigests    Unique keyed code digests.
     * @param   DateTimeImmutable  $confirmedAt        Confirmation instant, also checked against expiry.
     *
     * @return  bool  True only when one pending row changed and all code rows were installed.
     *
     * @since   2.0.0
     */
    public function activate(
        string $credentialId,
        string $subjectId,
        int $expectedVersion,
        int $acceptedTimeStep,
        array $recoveryDigests,
        DateTimeImmutable $confirmedAt,
    ): bool;

    /**
     * Advance the greatest accepted TOTP counter under an optimistic version fence.
     *
     * @param   string             $credentialId     Active credential UUID.
     * @param   int                $expectedVersion  Version read before verification.
     * @param   int                $timeStep         Counter that must exceed every previously accepted one.
     * @param   DateTimeImmutable  $acceptedAt       Audit timestamp stored with the advancement.
     *
     * @return  bool  True only for a live row whose version and monotonic counter allowed the update.
     *
     * @since   2.0.0
     */
    public function acceptTimeStep(
        string $credentialId,
        int $expectedVersion,
        int $timeStep,
        DateTimeImmutable $acceptedAt,
    ): bool;

    /**
     * Spend one recovery digest and advance the credential version in the same transaction.
     *
     * @param   string             $credentialId     Active credential UUID.
     * @param   int                $expectedVersion  Version read before hashing the submitted code.
     * @param   string             $digest           Keyed digest to consume.
     * @param   DateTimeImmutable  $consumedAt       Consumption timestamp.
     *
     * @return  bool  True only when both the credential fence and one unspent digest changed.
     *
     * @since   2.0.0
     */
    public function consumeRecoveryCode(
        string $credentialId,
        int $expectedVersion,
        string $digest,
        DateTimeImmutable $consumedAt,
    ): bool;
}
