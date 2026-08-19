<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordActionRejected;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordException;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\App\BusinessSurface\Application\BusinessOperationNotFound;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Throwable;

/**
 * Maps every expected business CLI failure onto stable JSON and a portable process status.
 *
 * Delivery code must not print arbitrary exception text: infrastructure exceptions may include SQL, paths
 * or input fragments, while authorization exceptions intentionally carry identities for audit logs only.
 * This mapper therefore selects its own messages, preserves stable application codes where safe, and folds
 * absent and unauthorized resources into non-enumerating answers.
 *
 * @since  2.0.0
 */
final readonly class BusinessConsoleFailureMapper
{
    /**
     * Invalid command syntax or missing option, matching `EX_USAGE`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const EXIT_USAGE = 64;

    /**
     * Malformed or semantically invalid input data, matching `EX_DATAERR`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const EXIT_DATA = 65;

    /**
     * Non-enumerating resource absence, matching `EX_NOINPUT`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const EXIT_NOT_FOUND = 66;

    /**
     * Installed runtime or schema unavailable, matching `EX_UNAVAILABLE`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const EXIT_UNAVAILABLE = 69;

    /**
     * Optimistic, uniqueness or replay conflict, matching `EX_CANTCREAT`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const EXIT_CONFLICT = 73;

    /**
     * Retryable or still-running operation, matching `EX_TEMPFAIL`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const EXIT_TEMPORARY = 75;

    /**
     * Authentication or authorization refusal, matching `EX_NOPERM`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const EXIT_PERMISSION = 77;

    /**
     * Unexpected internal failure; deliberately outside the sysexits classifications.
     *
     * @var    int
     * @since  2.0.0
     */
    public const EXIT_INTERNAL = 1;

    /**
     * Classify one throwable without returning its uncontrolled message or previous exception.
     *
     * Validation violations are the only application-provided text carried through: their value object
     * contract says the message is safe for delivery, and returning the whole bounded set lets an operator
     * repair a values document in one pass. Version numbers are similarly safe concurrency evidence. No
     * token, protected-file path, submitted value, internal record key or authorization subject is included.
     *
     * @param   Throwable  $exception  Failure caught at the outermost command boundary.
     *
     * @return  BusinessConsoleFailure  Stable process and JSON classification.
     *
     * @since   2.0.0
     */
    public function map(Throwable $exception): BusinessConsoleFailure
    {
        if (
            $exception instanceof AuthorizationDenied
            || $exception instanceof InsufficientCapability
            || $exception instanceof ApprovalDenied
        ) {
            return new BusinessConsoleFailure(
                self::EXIT_PERMISSION,
                'authorization.denied',
                'The requested operation is not permitted.',
            );
        }
        if (
            $exception instanceof BusinessRecordNotFound
            || $exception instanceof BusinessRecordDefinitionUnavailable
            || $exception instanceof BusinessOperationNotFound
        ) {
            return new BusinessConsoleFailure(
                self::EXIT_NOT_FOUND,
                'business_record.not_found',
                'The requested business resource was not found.',
            );
        }
        if ($exception instanceof BusinessRecordSchemaUnavailable) {
            return new BusinessConsoleFailure(
                self::EXIT_UNAVAILABLE,
                $exception->stableCode(),
                'The installed business-record schema is unavailable.',
            );
        }
        if ($exception instanceof BusinessRecordTemporarilyUnavailable) {
            return new BusinessConsoleFailure(
                self::EXIT_TEMPORARY,
                $exception->stableCode(),
                'The business-record operation is temporarily unavailable.',
            );
        }
        if ($exception instanceof BusinessRecordIdempotencyConflict) {
            $temporary = $exception->stableCode() === 'business_record.idempotency_in_progress';

            return new BusinessConsoleFailure(
                $temporary ? self::EXIT_TEMPORARY : self::EXIT_CONFLICT,
                $exception->stableCode(),
                $temporary
                    ? 'The idempotent business-record operation is still in progress.'
                    : 'The operation identifier cannot be used for this request.',
            );
        }
        if ($exception instanceof BusinessRecordVersionConflict) {
            return new BusinessConsoleFailure(
                self::EXIT_CONFLICT,
                $exception->stableCode(),
                'The business record changed after the supplied expected version.',
                [
                    'expected_version' => $exception->expectedVersion,
                    'actual_version' => $exception->actualVersion,
                ],
            );
        }
        if (
            $exception instanceof BusinessRecordUniqueConflict
            || $exception instanceof BusinessRecordReferenceConflict
        ) {
            return new BusinessConsoleFailure(
                self::EXIT_CONFLICT,
                $exception->stableCode(),
                'The business-record mutation conflicts with current data.',
            );
        }
        if ($exception instanceof BusinessRecordValidationFailed) {
            return new BusinessConsoleFailure(
                self::EXIT_DATA,
                $exception->stableCode(),
                'The business record failed validation.',
                ['violations' => array_map(
                    static fn (\Kumwe\App\BusinessRecord\Application\ValidationViolation $violation): array =>
                        $violation->toArray(),
                    $exception->violations,
                )],
            );
        }
        if ($exception instanceof InvalidBusinessRecordQuery) {
            return new BusinessConsoleFailure(
                self::EXIT_DATA,
                $exception->stableCode(),
                'The business-record query is invalid.',
            );
        }
        if (
            $exception instanceof BusinessRecordActionRejected
            || $exception instanceof BusinessRelationshipRejected
        ) {
            return new BusinessConsoleFailure(
                self::EXIT_DATA,
                $exception->stableCode(),
                'The requested business-record operation is invalid in its current state.',
            );
        }
        if ($exception instanceof BusinessRecordException) {
            return new BusinessConsoleFailure(
                self::EXIT_DATA,
                $exception->stableCode(),
                'The business-record operation failed.',
            );
        }
        if ($exception instanceof JsonException) {
            return new BusinessConsoleFailure(
                self::EXIT_DATA,
                'input.invalid_json',
                'A protected input document is not valid JSON.',
            );
        }
        if ($exception instanceof InvalidArgumentException) {
            return new BusinessConsoleFailure(
                self::EXIT_USAGE,
                'invocation.invalid',
                'The command invocation is invalid.',
            );
        }

        return new BusinessConsoleFailure(
            self::EXIT_INTERNAL,
            'internal_error',
            'The operation failed unexpectedly.',
        );
    }
}
