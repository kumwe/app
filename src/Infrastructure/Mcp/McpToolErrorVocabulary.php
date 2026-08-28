<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use DomainException;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Automation\AutomationNotFound;
use Kumwe\App\Application\Security\StepUpAuthorizationRequired;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionNotFound;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRevisionConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordActionRejected;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordException;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyRace;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordImmutable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodUndeclared;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\Conversion\Provider\MoneyRateUnavailable;
use Kumwe\Conversion\Provider\UnitConversionUnavailable;
use Kumwe\App\BusinessReporting\Application\ExportArtifactUnavailable;
use Kumwe\App\BusinessReporting\Application\ExportGenerationRejected;
use Kumwe\App\BusinessReporting\Application\ExportVersionConflict;
use Kumwe\App\BusinessReporting\Application\ReportUnavailable;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\App\BusinessSurface\Application\BusinessOperationNotFound;
use Kumwe\App\Content\Application\ContentModelNotFound;
use Kumwe\App\Content\Application\ContentNotFound;
use Kumwe\App\Content\Domain\VersionConflict;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Navigation\Application\NavigationNotFound;
use Kumwe\App\Navigation\Application\NavigationVersionConflict;
use Throwable;

/**
 * Owns the finite client-visible MCP tool-error vocabulary and its exception classifications.
 *
 * The mapper and retained machine contract consume these same rows. A new exception mapping, code,
 * sentence or retry decision therefore changes the generated registry and its digest instead of becoming
 * an undocumented branch in delivery code. Explicit `McpToolRefusal` instances are admitted only when their
 * complete safe triple matches a retained row; an ad-hoc refusal is treated as an unexpected defect.
 *
 * @phpstan-type ErrorClassification array{exception: class-string, stable_code?: string}
 * @phpstan-type ErrorDefinition array{
 *             code: string,
 *             message: string,
 *             retryable: bool,
 *             classifications: list<ErrorClassification>
 *         }
 *
 * @since  2.0.0
 */
final readonly class McpToolErrorVocabulary
{
    /**
     * Return the closed vocabulary in deterministic compatibility order.
     *
     * A `stable_code` narrows a class whose instances represent more than one machine condition. Business
     * record subclasses still declare their code here even when it is constructor-fixed, so changing that
     * module-level promise cannot silently preserve a stale MCP classification.
     *
     * @return  list<ErrorDefinition>  Stable envelopes and the exception conditions that select them.
     *
     * @since   2.0.0
     */
    public static function registry(): array
    {
        return [
            self::definition(
                'authorization.denied',
                'The requested operation is not permitted.',
                false,
                [
                    self::classification(AuthorizationDenied::class),
                    self::classification(InsufficientCapability::class),
                    self::classification(ApprovalDenied::class),
                ],
            ),
            self::definition(
                'authorization.step_up_required',
                'The requested operation requires a fresh human authorization proof.',
                false,
                [self::classification(StepUpAuthorizationRequired::class)],
            ),
            self::definition(
                'resource.not_found',
                'The requested resource is not available.',
                false,
                [
                    self::classification(ContentNotFound::class),
                    self::classification(ContentModelNotFound::class),
                    self::classification(NavigationNotFound::class),
                    self::classification(AutomationNotFound::class),
                    self::classification(BusinessDefinitionNotFound::class),
                    self::classification(BusinessSchemaNotFound::class),
                    self::classification(BusinessRecordNotFound::class, 'business_record.not_found'),
                    self::classification(
                        BusinessRecordDefinitionUnavailable::class,
                        'business_record.definition_unavailable',
                    ),
                    self::classification(BusinessOperationNotFound::class),
                    self::classification(ReportUnavailable::class),
                    self::classification(ExportArtifactUnavailable::class),
                ],
            ),
            self::definition(
                'business_record.idempotency_key_reused',
                'The operation identifier cannot be used for this request.',
                false,
                [
                    self::classification(
                        BusinessRecordIdempotencyConflict::class,
                        'business_record.idempotency_key_reused',
                    ),
                ],
            ),
            self::definition(
                'business_record.idempotency_replay_window_elapsed',
                'The operation identifier cannot be used for this request.',
                false,
                [
                    self::classification(
                        BusinessRecordIdempotencyConflict::class,
                        'business_record.idempotency_replay_window_elapsed',
                    ),
                ],
            ),
            self::definition(
                'business_record.idempotency_in_progress',
                'The idempotent operation is still in progress.',
                true,
                [
                    self::classification(
                        BusinessRecordIdempotencyConflict::class,
                        'business_record.idempotency_in_progress',
                    ),
                ],
            ),
            self::definition(
                'business_record.idempotency_corrupt',
                'The operation identifier cannot be used for this request.',
                false,
                [
                    self::classification(
                        BusinessRecordIdempotencyConflict::class,
                        'business_record.idempotency_corrupt',
                    ),
                ],
            ),
            self::definition(
                'conflict.version',
                'The requested resource changed after it was read.',
                false,
                [
                    self::classification(VersionConflict::class),
                    self::classification(NavigationVersionConflict::class),
                    self::classification(BusinessDefinitionRevisionConflict::class),
                    self::classification(
                        BusinessRecordVersionConflict::class,
                        'business_record.version_conflict',
                    ),
                    self::classification(ExportVersionConflict::class),
                ],
            ),
            self::definition(
                'conflict.current_state',
                'The requested operation conflicts with current state.',
                false,
                [self::classification(BusinessSchemaConflict::class)],
            ),
            self::definition(
                'service.temporarily_unavailable',
                'The requested operation is temporarily unavailable.',
                true,
                [
                    self::classification(
                        BusinessRecordTemporarilyUnavailable::class,
                        'business_record.temporarily_unavailable',
                    ),
                    self::classification(MoneyRateUnavailable::class),
                    self::classification(UnitConversionUnavailable::class),
                ],
            ),
            self::definition(
                'service.schema_unavailable',
                'The installed business schema cannot serve the requested operation.',
                false,
                [
                    self::classification(
                        BusinessRecordSchemaUnavailable::class,
                        'business_record.schema_unavailable',
                    ),
                ],
            ),
            self::definition(
                'request.no_longer_authorized',
                'The request no longer matches current authority or policy.',
                false,
                [self::classification(ExportGenerationRejected::class)],
            ),
            self::businessRecord(
                'business_record.action_rejected',
                BusinessRecordActionRejected::class,
            ),
            self::businessRecord(
                'business_record.idempotency_race',
                BusinessRecordIdempotencyRace::class,
            ),
            self::businessRecord(
                'business_record.immutable',
                BusinessRecordImmutable::class,
            ),
            self::businessRecord(
                'business_record.posting_period_closed',
                BusinessRecordPostingPeriodClosed::class,
            ),
            self::businessRecord(
                'business_record.posting_period_conflict',
                BusinessRecordPostingPeriodConflict::class,
            ),
            self::businessRecord(
                'business_record.posting_period_undeclared',
                BusinessRecordPostingPeriodUndeclared::class,
            ),
            self::businessRecord(
                'business_record.reference_conflict',
                BusinessRecordReferenceConflict::class,
            ),
            self::businessRecord(
                'business_record.relationship_rejected',
                BusinessRelationshipRejected::class,
            ),
            self::businessRecord(
                'business_record.unique_conflict',
                BusinessRecordUniqueConflict::class,
            ),
            self::businessRecord(
                'business_record.validation_failed',
                BusinessRecordValidationFailed::class,
            ),
            self::businessRecord(
                'business_record.invalid_query',
                InvalidBusinessRecordQuery::class,
            ),
            self::definition(
                'request.invalid',
                'The request is invalid for this operation.',
                false,
                [
                    self::classification(InvalidArgumentException::class),
                    self::classification(DomainException::class),
                ],
            ),
            self::definition(
                'operation.in_progress',
                'The requested operation is still in progress.',
                true,
                [self::classification(McpToolRefusal::class, 'operation.in_progress')],
            ),
            self::definition(
                'result.too_large',
                'The result is too large for bounded MCP delivery.',
                false,
                [self::classification(McpToolRefusal::class, 'result.too_large')],
            ),
        ];
    }

    /**
     * Select one retained error envelope for an expected throwable.
     *
     * @param   Throwable  $exception  Failure raised by a registered tool handler.
     *
     * @return  ?McpToolErrorEnvelope  Closed safe envelope, or null for an unexpected defect or drift.
     *
     * @since   2.0.0
     */
    public static function envelope(Throwable $exception): ?McpToolErrorEnvelope
    {
        foreach (self::registry() as $definition) {
            foreach ($definition['classifications'] as $classification) {
                $class = $classification['exception'];
                if (!$exception instanceof $class) {
                    continue;
                }
                if (
                    isset($classification['stable_code'])
                    && self::stableCode($exception) !== $classification['stable_code']
                ) {
                    continue;
                }
                if (
                    $exception instanceof McpToolRefusal
                    && (
                        $exception->safeMessage !== $definition['message']
                        || $exception->retryable !== $definition['retryable']
                    )
                ) {
                    continue;
                }

                return new McpToolErrorEnvelope(
                    $definition['code'],
                    $definition['message'],
                    $definition['retryable'],
                );
            }
        }

        return null;
    }

    /**
     * Build a retained definition for a business-record refusal using the shared redacted sentence.
     *
     * @param   string                                 $code       Module-owned stable machine code.
     * @param   class-string<BusinessRecordException>  $exception  Concrete exception bound to the code.
     *
     * @return  ErrorDefinition  Complete registry row.
     *
     * @since   2.0.0
     */
    private static function businessRecord(string $code, string $exception): array
    {
        return self::definition(
            $code,
            'The business-record operation was refused.',
            false,
            [self::classification($exception, $code)],
        );
    }

    /**
     * Build one definition while retaining its explicit JSON member order.
     *
     * @param   string                     $code             Stable dotted machine code.
     * @param   string                     $message          Fixed redacted sentence.
     * @param   bool                       $retryable        Whether an unchanged later retry may succeed.
     * @param   list<ErrorClassification>  $classifications  Exception conditions selecting this envelope.
     *
     * @return  ErrorDefinition  Complete registry row.
     *
     * @since   2.0.0
     */
    private static function definition(
        string $code,
        string $message,
        bool $retryable,
        array $classifications,
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'retryable' => $retryable,
            'classifications' => $classifications,
        ];
    }

    /**
     * Build one exception-classification row.
     *
     * @param   class-string  $exception   Throwable class or marker interface selecting the row.
     * @param   ?string       $stableCode  Required exception-owned code, or null when class is sufficient.
     *
     * @return  ErrorClassification  Retained classification condition.
     *
     * @since   2.0.0
     */
    private static function classification(string $exception, ?string $stableCode = null): array
    {
        if ($stableCode === null) {
            return ['exception' => $exception];
        }

        return ['exception' => $exception, 'stable_code' => $stableCode];
    }

    /**
     * Read the stable code carried by the two retained code-bearing exception families.
     *
     * @param   Throwable  $exception  Candidate code-bearing refusal.
     *
     * @return  ?string  Stable code, or null when the classification is class-only.
     *
     * @since   2.0.0
     */
    private static function stableCode(Throwable $exception): ?string
    {
        return match (true) {
            $exception instanceof McpToolRefusal => $exception->stableCode,
            $exception instanceof BusinessRecordException => $exception->stableCode(),
            default => null,
        };
    }
}
