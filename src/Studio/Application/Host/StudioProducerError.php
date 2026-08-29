<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use InvalidArgumentException;
use Kumwe\Producer\Error\Diagnostic;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Error\MessageReference;

/**
 * Constructs the App's delivery-safe errors directly in Producer's canonical taxonomy.
 *
 * @since  2.0.0
 */
final readonly class StudioProducerError
{
    /**
     * The fixed non-disclosing message every App host refusal exposes.
     *
     * @since  2.0.0
     */
    private const string MESSAGE_KEY = 'studio.host/request-refused';

    /**
     * The fixed fallback text every App host refusal exposes.
     *
     * @since  2.0.0
     */
    private const string MESSAGE_FALLBACK = 'The Studio host request could not be completed.';

    /**
     * Create one canonical Producer error from delivery-safe App policy facts.
     *
     * @param   string       $category                Closed Producer refusal category.
     * @param   string       $diagnosticCode          Stable delivery-safe diagnostic code.
     * @param   string|null  $revision                Safe current revision for a conflict.
     * @param   bool         $retryable               Whether an unavailable refusal is transient.
     * @param   int|null     $retryAfterMilliseconds  Bounded retry delay for a retryable refusal.
     * @param   string|null  $correlationId           Delivery-safe support correlation identifier.
     *
     * @return  HostError  Canonical error ready for Producer's responder.
     *
     * @throws  InvalidArgumentException  When the supplied category or category-specific fields are invalid.
     *
     * @since   2.0.0
     */
    public static function error(
        string $category,
        string $diagnosticCode,
        ?string $revision = null,
        bool $retryable = false,
        ?int $retryAfterMilliseconds = null,
        ?string $correlationId = null,
    ): HostError {
        $message = new MessageReference(self::MESSAGE_KEY, self::MESSAGE_FALLBACK);
        $diagnostics = [new Diagnostic(
            $diagnosticCode,
            'blocking',
            new MessageReference($diagnosticCode, self::MESSAGE_FALLBACK),
        )];

        return match ($category) {
            'invalid-request' => HostError::invalidRequest($message, $diagnostics, $correlationId),
            'unauthenticated' => HostError::unauthenticated($message, $diagnostics, $correlationId),
            'forbidden' => HostError::forbidden($message, $diagnostics, $correlationId),
            'not-found' => HostError::notFound($message, $diagnostics, $correlationId),
            'conflict' => HostError::conflict($message, $revision, $diagnostics, $correlationId),
            'validation-failed' => HostError::validationFailed($message, $diagnostics, $correlationId),
            'incompatible' => HostError::incompatible($message, $diagnostics, $correlationId),
            'limit-exceeded' => HostError::limitExceeded($message, $diagnostics, $correlationId),
            'rate-limited' => HostError::rateLimited(
                $message,
                $retryAfterMilliseconds,
                $diagnostics,
                $correlationId,
            ),
            'unavailable' => HostError::unavailable(
                $message,
                $retryable,
                $retryAfterMilliseconds,
                $diagnostics,
                $correlationId,
            ),
            'cancelled' => HostError::cancelled($message, $diagnostics, $correlationId),
            'internal' => HostError::internal($message, $diagnostics, $correlationId),
            default => throw new InvalidArgumentException('The Studio host refusal category is unknown.'),
        };
    }

    /**
     * Throw one canonical Producer refusal, optionally committing a safe failed mutation state.
     *
     * @param   string       $category                Closed Producer refusal category.
     * @param   string       $diagnosticCode          Stable delivery-safe diagnostic code.
     * @param   string|null  $revision                Safe current revision for a conflict.
     * @param   bool         $retryable               Whether an unavailable refusal is transient.
     * @param   int|null     $retryAfterMilliseconds  Bounded retry delay for a retryable refusal.
     * @param   bool         $commitsState            Whether safe mutation failure state must commit and replay.
     *
     * @return  never
     *
     * @since   2.0.0
     */
    public static function refuse(
        string $category,
        string $diagnosticCode,
        ?string $revision = null,
        bool $retryable = false,
        ?int $retryAfterMilliseconds = null,
        bool $commitsState = false,
    ): never {
        throw new HostRefusal(
            self::error($category, $diagnosticCode, $revision, $retryable, $retryAfterMilliseconds),
            $commitsState,
        );
    }

    /**
     * Resolve the HTTP status paired with one Producer refusal category.
     *
     * @param   string  $category  Closed Producer refusal category.
     *
     * @return  int  HTTP status preserving the refusal's semantic category.
     *
     * @throws  InvalidArgumentException  When the category is unknown.
     *
     * @since   2.0.0
     */
    public static function status(string $category): int
    {
        return match ($category) {
            'invalid-request', 'incompatible', 'cancelled' => 400,
            'unauthenticated' => 401,
            'forbidden' => 403,
            'not-found' => 404,
            'conflict' => 409,
            'limit-exceeded' => 413,
            'validation-failed' => 422,
            'rate-limited' => 429,
            'internal' => 500,
            'unavailable' => 503,
            default => throw new InvalidArgumentException('The Studio host refusal category is unknown.'),
        };
    }
}
