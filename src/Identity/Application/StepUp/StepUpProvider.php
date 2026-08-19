<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\StepUp;

use Kumwe\App\Identity\Domain\StepUp\StepUpEnrollmentCompletion;
use Kumwe\App\Identity\Domain\StepUp\StepUpEnrollmentSetup;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;

/**
 * Provider contract for TOTP enrollment, ordinary challenges, and recovery challenges.
 *
 * @since  2.0.0
 */
interface StepUpProvider
{
    /**
     * Start a short-lived enrollment and disclose its secret once.
     *
     * @param   string  $subjectId     Authenticated actor UUID.
     * @param   string  $issuer        Product or installation label shown by authenticators.
     * @param   string  $accountLabel  Account label shown by authenticators.
     *
     * @return  StepUpEnrollmentSetup  Pending enrollment material.
     *
     * @throws  \InvalidArgumentException  When an active credential already exists or labels are invalid.
     *
     * @since   2.0.0
     */
    public function beginEnrollment(string $subjectId, string $issuer, string $accountLabel): StepUpEnrollmentSetup;

    /**
     * Confirm enrollment, rotate the session, and return recovery codes once.
     *
     * @param   StepUpIntent  $intent        Server-resolved session and operation context.
     * @param   string        $enrollmentId  Pending enrollment UUID.
     * @param   string        $code          Authenticator TOTP candidate.
     * @param   string        $source        Trusted-proxy-resolved attempt source.
     *
     * @return  StepUpEnrollmentCompletion  Fresh proof and one-time recovery-code list.
     *
     * @throws  StepUpRejected  When enrollment, code, expiry, or atomic acceptance fails.
     *
     * @since   2.0.0
     */
    public function confirmEnrollment(
        StepUpIntent $intent,
        string $enrollmentId,
        string $code,
        string $source,
    ): StepUpEnrollmentCompletion;

    /**
     * Verify a TOTP challenge, atomically reject replay, and rotate the session.
     *
     * @param   StepUpIntent  $intent  Server-resolved session and operation context.
     * @param   string        $code    Authenticator TOTP candidate.
     * @param   string        $source  Trusted-proxy-resolved attempt source.
     *
     * @return  StepUpVerification  Context-bound proof paired with the replacement session.
     *
     * @throws  StepUpRejected  When no active credential or an invalid or replayed code is supplied.
     *
     * @since   2.0.0
     */
    public function challenge(StepUpIntent $intent, string $code, string $source): StepUpVerification;

    /**
     * Spend a recovery code, atomically reject reuse, and rotate the session.
     *
     * @param   StepUpIntent  $intent        Server-resolved session and operation context.
     * @param   string        $recoveryCode  One-time code as displayed during enrollment.
     * @param   string        $source        Trusted-proxy-resolved attempt source.
     *
     * @return  StepUpVerification  Context-bound recovery proof paired with the replacement session.
     *
     * @throws  StepUpRejected  When no active credential or an invalid or spent code is supplied.
     *
     * @since   2.0.0
     */
    public function recover(StepUpIntent $intent, string $recoveryCode, string $source): StepUpVerification;
}
