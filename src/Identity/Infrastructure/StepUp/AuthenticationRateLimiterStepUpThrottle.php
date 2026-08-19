<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Infrastructure\StepUp;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\App\Identity\Application\StepUp\StepUpAttemptThrottle;

/**
 * Domain-separated adapter from step-up attempts to the shared Redis authentication budget.
 *
 * @since  2.0.0
 */
final readonly class AuthenticationRateLimiterStepUpThrottle implements StepUpAttemptThrottle
{
    /**
     * Bind the adapter to the shared limiter and a dedicated HMAC key.
     *
     * @param   AuthenticationRateLimiter  $limiter  Shared fail-closed distributed counter.
     * @param   string                     $key      Raw key of at least 32 bytes.
     *
     * @throws  InvalidArgumentException  When the HMAC key is too short.
     *
     * @since   2.0.0
     */
    public function __construct(
        private AuthenticationRateLimiter $limiter,
        private string $key,
    ) {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('The step-up throttle key must contain at least 32 bytes.');
        }
    }

    /**
     * Count a keyed, purpose-separated actor and origin pair.
     *
     * @param   string  $subjectId  Authenticated actor UUID.
     * @param   string  $source     Trusted origin or `unknown`.
     * @param   string  $purpose    Challenge purpose.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertAllowed(string $subjectId, string $source, string $purpose): void
    {
        [$subjectDigest, $sourceDigest] = $this->digests($subjectId, $source, $purpose);
        $this->limiter->assertAllowed($subjectDigest, $sourceDigest);
    }

    /**
     * Forward a result against the same keyed pair.
     *
     * @param   string  $subjectId  Authenticated actor UUID.
     * @param   string  $source     Trusted origin or `unknown`.
     * @param   string  $purpose    Challenge purpose.
     * @param   bool    $succeeded  Whether atomic credential acceptance succeeded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $subjectId, string $source, string $purpose, bool $succeeded): void
    {
        [$subjectDigest, $sourceDigest] = $this->digests($subjectId, $source, $purpose);
        $this->limiter->record($subjectDigest, $sourceDigest, $succeeded);
    }

    /**
     * Hide raw actor, source, and purpose values before they enter the shared cache.
     *
     * @param   string  $subjectId  Authenticated actor UUID.
     * @param   string  $source     Trusted origin or `unknown`.
     * @param   string  $purpose    Challenge purpose.
     *
     * @return  array{string, string}  Subject and source HMACs.
     *
     * @since   2.0.0
     */
    private function digests(string $subjectId, string $source, string $purpose): array
    {
        return [
            hash_hmac('sha256', "step-up-subject\0" . $purpose . "\0" . $subjectId, $this->key),
            hash_hmac('sha256', "step-up-source\0" . $purpose . "\0" . $source, $this->key),
        ];
    }
}
