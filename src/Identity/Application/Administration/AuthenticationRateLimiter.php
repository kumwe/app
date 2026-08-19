<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

/**
 * Budget of failed sign-in attempts one account may spend from one request origin.
 *
 * `DoctrineAdministratorIdentityGateway` calls `assertAllowed()` before it reads a stored password
 * hash and `record()` immediately after it verifies one, so credential stuffing is stopped at the
 * door rather than after a hash comparison has already been paid for. Both arguments arrive as keyed
 * digests, never as an email address or a client address, which lets an implementation keep its
 * counters in a shared store without holding anything that identifies a person. An implementation
 * owes two behaviours: refusal once the budget is spent, and a counter that a successful sign-in
 * clears so a legitimate operator is not locked out by their own earlier typos.
 *
 * @since  2.0.0
 */
interface AuthenticationRateLimiter
{
    /**
     * Refuse the attempt when this account and origin pair has already spent its budget.
     *
     * Called before the credential is looked up, so a throttled caller learns nothing about whether
     * the account exists.
     *
     * @param   string  $subjectDigest  Keyed digest of the normalised email being authenticated.
     * @param   string  $sourceDigest   Keyed digest of the origin the attempt arrives from.
     *
     * @return  void
     *
     * @throws  AuthenticationThrottled  When the pair has exhausted its attempts for the current window.
     *
     * @since   2.0.0
     */
    public function assertAllowed(string $subjectDigest, string $sourceDigest): void;

    /**
     * Report how an attempt ended so the budget tracks it.
     *
     * A successful attempt clears the pair's counter; a failed one leaves it standing, which is what
     * moves a run of wrong passwords towards the ceiling.
     *
     * @param   string  $subjectDigest  Keyed digest of the normalised email that was authenticated.
     * @param   string  $sourceDigest   Keyed digest of the origin the attempt arrived from.
     * @param   bool    $succeeded      Whether the presented credential verified.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $subjectDigest, string $sourceDigest, bool $succeeded): void;
}
