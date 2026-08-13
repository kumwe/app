<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\StepUp;

/**
 * Administrator-scoped provider instance whose session rotator targets only administrator sessions.
 *
 * The marker prevents the composition root from accidentally injecting the portal provider into an
 * administrator flow even though both expose the same enrollment and challenge operations.
 *
 * It also carries the one operation only the administrator security workspace offers. Recovery-code
 * reissue is deliberately not on the shared `StepUpProvider` port: the portal has no screen that
 * reprints codes, and widening the shared contract would oblige every provider — including the test
 * doubles that stand in for it — to implement an operation no portal flow can reach.
 *
 * @since  2.0.0
 */
interface AdministratorStepUpProvider extends StepUpProvider
{
    /**
     * Replace a subject's whole recovery-code set and disclose the new codes exactly once.
     *
     * The counterpart of the codes handed out at enrollment, for the operator who spent or lost them
     * and would otherwise be one broken authenticator away from needing an administrator. Possession of
     * the current authenticator is what authorizes it, and the caller proves that before calling: the
     * administrator surface reaches this only after a successful, payload-bound TOTP challenge whose
     * proof it has already consumed, so nothing here re-checks a credential and nothing here accepts
     * one. Recovery codes deliberately cannot be reissued by presenting a recovery code, because a
     * single leaked code would otherwise mint ten replacements and lock the real holder out.
     *
     * The replacement is total rather than additive, so the count of usable codes afterwards is exactly
     * the number returned and any list printed earlier stops working the moment this returns.
     *
     * @param   string  $subjectId  Authenticated actor whose active credential is reissued against.
     *
     * @return  list<string>  Plaintext recovery codes, shown once and unrecoverable afterwards.
     *
     * @throws  StepUpRejected  When the subject has no active credential, or the credential advanced
     *          under a concurrent challenge before the replacement could land.
     *
     * @since   2.0.0
     */
    public function reissueRecoveryCodes(string $subjectId): array;
}
