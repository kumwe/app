<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\StepUp;

use DateTimeImmutable;
use Kumwe\App\Identity\Domain\StepUp\TotpCredential;

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
     * @param   string             $credentialId      Enrollment UUID.
     * @param   string             $subjectId         Authenticated actor UUID.
     * @param   int                $expectedVersion   Version read before verification.
     * @param   int                $acceptedTimeStep  TOTP counter proven during confirmation.
     * @param   list<string>       $recoveryDigests   Unique keyed code digests.
     * @param   DateTimeImmutable  $confirmedAt       Confirmation instant, also checked against expiry.
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

    /**
     * Retire every pending and active credential a subject holds, with the reason that justified it.
     *
     * Revocation is expressed per subject rather than per credential because that is the operation an
     * operator actually performs: a lost or compromised authenticator is reported by the person, not by
     * the identifier of a row they cannot see, and the invariant `replacePending()` enforces means at
     * most one credential can be active anyway. Implementations must also destroy the unspent recovery
     * digests belonging to the retired credentials, so a code printed under the old authenticator
     * cannot be presented afterwards, and must leave already-consumed digests alone because those are
     * evidence. Nothing here touches the security epoch: the caller owns that decision and the
     * transaction it lands in.
     *
     * @param   string             $subjectId  Subject whose second factor is being retired.
     * @param   DateTimeImmutable  $revokedAt  Instant recorded as the retirement time.
     * @param   string             $reason     Operator justification stored beside each retired row.
     *
     * @return  int  How many credentials were retired; zero when the subject had none enrolled.
     *
     * @since   2.0.0
     */
    public function revokeForSubject(string $subjectId, DateTimeImmutable $revokedAt, string $reason): int;

    /**
     * Replace one active credential's whole recovery-code set under an optimistic version fence.
     *
     * Reissue is a replacement rather than a top-up: the previous digests are removed, spent and
     * unspent alike, so the count of usable codes after the call is exactly the count supplied and a
     * list printed earlier stops working the moment a new one is printed. Implementations must refuse
     * unless the credential is live and still carries the expected version, so a reissue racing a
     * recovery consumption cannot resurrect a code the other request just spent.
     *
     * @param   string             $credentialId     Active credential UUID.
     * @param   string             $subjectId        Authenticated actor UUID the credential must belong to.
     * @param   int                $expectedVersion  Version read before the replacement set was generated.
     * @param   list<string>       $digests          Unique keyed digests replacing the stored set.
     * @param   DateTimeImmutable  $reissuedAt       Instant recorded as the creation time of each digest.
     *
     * @return  bool  True only when one live credential advanced and every digest was stored.
     *
     * @throws  \InvalidArgumentException  When the digest list is empty, malformed or duplicated.
     *
     * @since   2.0.0
     */
    public function replaceRecoveryCodes(
        string $credentialId,
        string $subjectId,
        int $expectedVersion,
        array $digests,
        DateTimeImmutable $reissuedAt,
    ): bool;
}
