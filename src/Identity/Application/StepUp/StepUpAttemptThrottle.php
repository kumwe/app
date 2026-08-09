<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\StepUp;

/**
 * Shared attempt budget for a step-up subject, source, and narrow purpose.
 *
 * Implementations must domain-separate and key-digest the raw values before a shared cache sees them,
 * and must fail closed when that cache is unavailable.
 *
 * @since  2.0.0
 */
interface StepUpAttemptThrottle
{
    /**
     * Count and admit an attempt only while its budget remains.
     *
     * @param   string  $subjectId  Authenticated actor UUID.
     * @param   string  $source     Trusted-proxy-resolved source, or `unknown`.
     * @param   string  $purpose    Challenge purpose used for domain separation.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled  When exhausted.
     *
     * @since   2.0.0
     */
    public function assertAllowed(string $subjectId, string $source, string $purpose): void;

    /**
     * Clear a successful attempt's budget or retain a failed attempt.
     *
     * @param   string  $subjectId  Authenticated actor UUID.
     * @param   string  $source     Trusted-proxy-resolved source, or `unknown`.
     * @param   string  $purpose    Challenge purpose used for domain separation.
     * @param   bool    $succeeded  Whether the credential was accepted atomically.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $subjectId, string $source, string $purpose, bool $succeeded): void;
}
