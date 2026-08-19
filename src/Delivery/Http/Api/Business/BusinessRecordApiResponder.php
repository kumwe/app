<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Business;

use DomainException;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordActionRejected;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordImmutable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodUndeclared;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\App\BusinessRecord\Application\RecordHistoryResult;
use Kumwe\App\BusinessRecord\Application\RecordMutationResult;
use Kumwe\App\BusinessRecord\Application\ValidationViolation;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\App\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Renders generic business-record results and their stable RFC 9457 failure contract.
 *
 * Every success passes through `BusinessRecordApiPresenter`, which is the boundary that removes internal
 * identifiers and withheld metadata. Every modeled failure passes through the shared problem factory.
 * Missing, policy-denied and approval-denied resources deliberately collapse onto one 404 document;
 * validation publishes only the bounded violations the application service has already made safe for the
 * caller, and infrastructure details never become response text.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordApiResponder
{
    /**
     * Wire public result projection to the common problem-document factory.
     *
     * @param  BusinessRecordApiPresenter     $presenter  Removes internal application metadata.
     * @param  ProblemDetailsResponseFactory  $problems   Builds RFC 9457 `application/problem+json` responses.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordApiPresenter $presenter,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Render one record with the strong entity tag a later mutation must echo.
     *
     * @param   BusinessRecordView                             $record   Policy-filtered application view.
     * @param   int                                            $status   Successful HTTP status.
     * @param   array<non-empty-string, array<string>|string>  $headers  Additional or replacement headers.
     *
     * @return  ResponseInterface  Public record JSON with `ETag` and `Cache-Control: no-store`.
     *
     * @since   2.0.0
     */
    public function record(BusinessRecordView $record, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse($this->presenter->record($record), $status, [
            'ETag' => (string) EntityTag::fromVersion($record->version),
            'Cache-Control' => 'no-store',
            ...$headers,
        ]);
    }

    /**
     * Render one bounded browse page without inventing a total or exposing cacheable policy output.
     *
     * @param   RecordBrowseResult  $result  Page, opaque cursor and explicitly requested aggregates.
     *
     * @return  ResponseInterface  Public page JSON with `Cache-Control: no-store`.
     *
     * @since   2.0.0
     */
    public function browse(RecordBrowseResult $result): ResponseInterface
    {
        return new JsonResponse(
            $this->presenter->browse($result),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Render one application-owned idempotent mutation outcome.
     *
     * The record service, not HTTP middleware, decides replay. A replay is marked with the established
     * `Idempotency-Replayed: true` header; a fresh response omits that header, matching the existing API
     * convention. The returned version is always tagged, including a deletion result whose row no longer
     * survives, because it is part of the canonical replayed outcome.
     *
     * @param   RecordMutationResult                           $result   Applied or replayed mutation result.
     * @param   int                                            $status   201 for create, otherwise 200.
     * @param   array<non-empty-string, array<string>|string>  $headers  Additional or replacement headers.
     *
     * @return  ResponseInterface  Public mutation JSON with strong `ETag` and replay metadata.
     *
     * @since   2.0.0
     */
    public function mutation(RecordMutationResult $result, int $status = 200, array $headers = []): ResponseInterface
    {
        $responseHeaders = [
            'ETag' => (string) EntityTag::fromVersion($result->version),
            'Cache-Control' => 'no-store',
            ...$headers,
        ];
        if ($result->replayed) {
            $responseHeaders['Idempotency-Replayed'] = 'true';
        }

        return new JsonResponse($this->presenter->mutation($result), $status, $responseHeaders);
    }

    /**
     * Render one bounded history page.
     *
     * @param   RecordHistoryResult  $result  Policy-filtered revision page.
     *
     * @return  ResponseInterface  Public revision JSON with `Cache-Control: no-store`.
     *
     * @since   2.0.0
     */
    public function history(RecordHistoryResult $result): ResponseInterface
    {
        return new JsonResponse(
            $this->presenter->history($result),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Render one caller-visible custom view document.
     *
     * @param   array<string, mixed>  $document  Contract-validated view metadata and data.
     *
     * @return  ResponseInterface  Omission-safe JSON with authenticated output marked no-store.
     *
     * @since   2.0.0
     */
    public function document(array $document): ResponseInterface
    {
        return new JsonResponse(
            $this->presenter->document($document),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Render the canonical array envelope returned by the shared generated-business action facade.
     *
     * Ordinary actions retain the record projector's exact keys. Custom actions may add their bounded
     * contract result under `result`; both receive the same entity tag and replay header conventions.
     *
     * @param   array<string, mixed>  $result  Canonical or contract-extended action result.
     *
     * @return  ResponseInterface  Omission-safe mutation JSON with concurrency and replay headers.
     *
     * @throws  InvalidArgumentException  When the facade result lacks valid version or replay metadata.
     *
     * @since   2.0.0
     */
    public function surfaceMutation(array $result): ResponseInterface
    {
        $version = $result['version'] ?? null;
        $replayed = $result['replayed'] ?? null;
        if (!is_int($version) || $version < 1 || !is_bool($replayed)) {
            throw new InvalidArgumentException('The generated business action result is invalid.');
        }
        $headers = [
            'ETag' => (string) EntityTag::fromVersion($version),
            'Cache-Control' => 'no-store',
        ];
        if ($replayed) {
            $headers['Idempotency-Replayed'] = 'true';
        }

        return new JsonResponse($this->presenter->document($result), 200, $headers);
    }

    /**
     * Render the result of requesting approval for one high-impact record action.
     *
     * A rule may decide no approval is required, in which case the endpoint answers 200 with `required`
     * false. A stored request answers 201 and publishes only its caller-facing request identity and a
     * location; frozen rule, actor and payload-digest evidence remains behind the approval resource policy.
     *
     * @param   string|null  $requestId  New approval UUID, or null when no active rule requires approval.
     *
     * @return  ResponseInterface  Approval requirement JSON, with a location when a request was stored.
     *
     * @since   2.0.0
     */
    public function approval(?string $requestId): ResponseInterface
    {
        $headers = ['Cache-Control' => 'no-store'];
        if ($requestId !== null) {
            $headers['Location'] = '/api/v1/business/approvals/' . rawurlencode($requestId);
        }

        return new JsonResponse([
            'required' => $requestId !== null,
            'approval_request_id' => $requestId,
        ], $requestId === null ? 200 : 201, $headers);
    }

    /**
     * Translate a modeled business-record failure into its stable problem document.
     *
     * `BusinessRecordValidationFailed` is the only case carrying extension members. Its application
     * service checks field access before rule validation and substitutes the generic `record` violation
     * for denied input, so the violations exposed here contain only caller-visible handles. Compiler and
     * infrastructure messages are replaced with fixed wording to prevent schema and storage disclosure.
     * Unrecognized failures are rethrown for the global error boundary rather than guessed into a 4xx.
     *
     * @param   Throwable  $exception  Failure raised by request parsing or the application service.
     * @param   string     $instance   URI of this occurrence.
     *
     * @return  ResponseInterface  Stable RFC 9457 response with authenticated output marked `no-store`.
     *
     * @since   2.0.0
     */
    public function problem(Throwable $exception, string $instance): ResponseInterface
    {
        $response = match (true) {
            $exception instanceof BusinessRecordNotFound,
            $exception instanceof BusinessRecordDefinitionUnavailable,
            $exception instanceof ApprovalDenied => $this->problems->create(
                404,
                'Business Record Not Found',
                'The business record was not found.',
                'urn:kumwe:problem:business-record-not-found',
                $instance,
            ),
            $exception instanceof BusinessRecordPreconditionFailed,
            $exception instanceof BusinessRecordVersionConflict => $this->problems->create(
                412,
                'Precondition Failed',
                'The supplied record version is no longer current.',
                'urn:kumwe:problem:precondition-failed',
                $instance,
            ),
            $exception instanceof BusinessRecordImmutable => $this->problems->create(
                409,
                'Business Record Immutable',
                'The business record is immutable in its current workflow state and is corrected by a linked reversal.',
                'urn:kumwe:problem:business-record-immutable',
                $instance,
            ),
            $exception instanceof BusinessRecordPostingPeriodClosed => $this->problems->create(
                409,
                'Business Posting Period Closed',
                'The record is dated inside a closed posting period.',
                'urn:kumwe:problem:business-record-posting-period-closed',
                $instance,
            ),
            $exception instanceof BusinessRecordPostingPeriodUndeclared => $this->problems->create(
                409,
                'Business Posting Period Undeclared',
                'No declared posting period contains the record\'s posting date.',
                'urn:kumwe:problem:business-record-posting-period-undeclared',
                $instance,
            ),
            $exception instanceof BusinessRecordUniqueConflict => $this->problems->create(
                409,
                'Conflict',
                'A unique business-record value is already in use.',
                'urn:kumwe:problem:business-record-unique-conflict',
                $instance,
            ),
            $exception instanceof BusinessRecordReferenceConflict => $this->problems->create(
                409,
                'Conflict',
                'A business-record reference prevents this mutation.',
                'urn:kumwe:problem:business-record-reference-conflict',
                $instance,
            ),
            $exception instanceof BusinessRecordIdempotencyConflict => $this->problems->create(
                409,
                'Idempotency Conflict',
                $exception->getMessage(),
                'urn:kumwe:problem:' . str_replace(['.', '_'], '-', $exception->stableCode()),
                $instance,
            ),
            $exception instanceof BusinessRecordValidationFailed => $this->problems->create(
                422,
                'Business Record Validation Failed',
                'The business record failed validation.',
                'urn:kumwe:problem:business-record-validation-failed',
                $instance,
                ['violations' => array_map(
                    static fn (ValidationViolation $violation): array => $violation->toArray(),
                    $exception->violations,
                )],
            ),
            $exception instanceof InvalidBusinessRecordQuery => $this->problems->create(
                422,
                'Invalid Business Record Query',
                'The business-record query is invalid for this definition.',
                'urn:kumwe:problem:invalid-business-record-query',
                $instance,
            ),
            $exception instanceof BusinessRecordActionRejected => $this->problems->create(
                422,
                'Business Record Action Rejected',
                'The requested business action is not valid for this record.',
                'urn:kumwe:problem:business-record-action-rejected',
                $instance,
            ),
            $exception instanceof BusinessRelationshipRejected => $this->problems->create(
                422,
                'Business Relationship Rejected',
                'The requested business relationship mutation is invalid.',
                'urn:kumwe:problem:business-relationship-rejected',
                $instance,
            ),
            $exception instanceof BusinessRecordSchemaUnavailable,
            $exception instanceof BusinessRecordTemporarilyUnavailable => $this->problems->create(
                503,
                'Service Unavailable',
                'The business-record operation is temporarily unavailable.',
                'urn:kumwe:problem:business-record-unavailable',
                $instance,
            ),
            $exception instanceof AuthorizationDenied,
            $exception instanceof InsufficientCapability => $this->problems->create(
                403,
                'Forbidden',
                'The authenticated identity is not authorized for this operation.',
                'urn:kumwe:problem:authorization-denied',
                $instance,
            ),
            $exception instanceof InvalidArgumentException => $this->problems->create(
                422,
                'Unprocessable Content',
                'The business-record request is invalid.',
                'urn:kumwe:problem:validation-failed',
                $instance,
            ),
            $exception instanceof DomainException => $this->problems->create(
                422,
                'Unprocessable Content',
                'The business-record request could not be processed.',
                'urn:kumwe:problem:validation-failed',
                $instance,
            ),
            default => throw $exception,
        };

        $response = $response->withHeader('Cache-Control', 'no-store');
        if (
            $exception instanceof BusinessRecordSchemaUnavailable
            || $exception instanceof BusinessRecordTemporarilyUnavailable
            || (
                $exception instanceof BusinessRecordIdempotencyConflict
                && $exception->stableCode() === 'business_record.idempotency_in_progress'
            )
        ) {
            $response = $response->withHeader('Retry-After', '1');
        }

        return $response;
    }
}
