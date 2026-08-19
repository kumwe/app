<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Business;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use DateTimeImmutable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordImmutable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodUndeclared;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\App\BusinessRecord\Application\RecordMutationResult;
use Kumwe\App\BusinessRecord\Application\ValidationViolation;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiPresenter;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiResponder;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(BusinessRecordApiResponder::class)]
/**
 * Proves generated-business REST responses expose stable, non-enumerating public documents.
 *
 * @since  2.0.0
 */
final class BusinessRecordApiResponderTest extends TestCase
{
    /**
     * Internal definition identity used to prove it is withheld.
     *
     * @var    string
     * @since  2.0.0
     */
    private const DEFINITION = '018f22e2-7c8b-7ab0-8f3a-88e8026bb601';

    /**
     * Internal storage identity used to prove it is withheld.
     *
     * @var    string
     * @since  2.0.0
     */
    private const RECORD_KEY = '018f22e2-7c8b-7ab0-8f3a-88e8026bb602';

    /**
     * Public problem instance used by responder assertions.
     *
     * @var    string
     * @since  2.0.0
     */
    private const INSTANCE = 'https://kumwe.test/api/v1/business/records/core.invoice/INV-0001';

    /**
     * Proves a replay is marked without disclosing ledger or storage identities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRendersApplicationReplayWithoutInternalLedgerIdentity(): void
    {
        $response = $this->responder()->mutation(new RecordMutationResult(
            self::DEFINITION,
            2,
            self::RECORD_KEY,
            'INV-0001',
            4,
            'approved',
            'action',
            replayed: true,
        ));
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"v4"', $response->getHeaderLine('ETag'));
        self::assertSame('true', $response->getHeaderLine('Idempotency-Replayed'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('INV-0001', $body['record_id']);
        self::assertArrayNotHasKey('record_key', $body);
        self::assertArrayNotHasKey('definition_id', $body);
    }

    /**
     * Proves absent records and denied approvals collapse to the same public problem.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingAndDeniedApprovalAreTheSameNonEnumeratingProblem(): void
    {
        $missing = $this->responder()->problem(new BusinessRecordNotFound(), self::INSTANCE);
        $denied = $this->responder()->problem(new ApprovalDenied(), self::INSTANCE);

        self::assertSame(404, $missing->getStatusCode());
        self::assertSame((string) $missing->getBody(), (string) $denied->getBody());
        self::assertSame('application/problem+json', $missing->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $missing->getHeaderLine('Cache-Control'));
    }

    /**
     * Proves optimistic concurrency and idempotency conflicts keep stable HTTP semantics.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapsConcurrencyAndConflictFamiliesToStableStatuses(): void
    {
        $precondition = $this->responder()->problem(new BusinessRecordVersionConflict(3, 4), self::INSTANCE);
        $idempotency = $this->responder()->problem(
            new BusinessRecordIdempotencyConflict('in_progress'),
            self::INSTANCE,
        );

        self::assertSame(412, $precondition->getStatusCode());
        self::assertSame('urn:kumwe:problem:precondition-failed', $this->body($precondition)['type']);
        self::assertSame(409, $idempotency->getStatusCode());
        self::assertSame('1', $idempotency->getHeaderLine('Retry-After'));
    }

    /**
     * Proves an immutable-record refusal is its own stable, non-retryable conflict problem.
     *
     * The refusal is not a policy denial and must not read as one: the caller may be fully authorized,
     * the document is simply closed, so the surface answers with a named 409 whose type a client can
     * branch on to offer the reversal path instead of a retry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapsAnImmutableRecordRefusalToItsOwnStableConflict(): void
    {
        $response = $this->responder()->problem(new BusinessRecordImmutable('approved'), self::INSTANCE);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('urn:kumwe:problem:business-record-immutable', $this->body($response)['type']);
        self::assertSame('', $response->getHeaderLine('Retry-After'), 'A closed document does not reopen on retry.');
        self::assertStringNotContainsString('approved', (string) json_encode($this->body($response)));
    }

    /**
     * Proves a closed-period refusal is its own stable conflict rather than the global error boundary.
     *
     * The caller may be fully authorized and the record perfectly valid; the period its posting date
     * falls in has been closed, so the surface answers a named 409 a client can branch on, and the
     * period key stays out of the body because administrative structure is not the caller's to read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapsAClosedPostingPeriodRefusalToItsOwnStableConflict(): void
    {
        $refusal = new BusinessRecordPostingPeriodClosed('fy2026-q1', new DateTimeImmutable('2026-02-14T00:00:00Z'));
        $response = $this->responder()->problem($refusal, self::INSTANCE);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('urn:kumwe:problem:business-record-posting-period-closed', $this->body($response)['type']);
        self::assertStringNotContainsString('fy2026-q1', (string) json_encode($this->body($response)));
    }

    /**
     * Proves an undeclared-period refusal is its own stable conflict with the posting date withheld.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapsAnUndeclaredPostingPeriodRefusalToItsOwnStableConflict(): void
    {
        $refusal = new BusinessRecordPostingPeriodUndeclared(new DateTimeImmutable('2026-03-01T00:00:00Z'));
        $response = $this->responder()->problem($refusal, self::INSTANCE);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('urn:kumwe:problem:business-record-posting-period-undeclared', $this->body($response)['type']);
        self::assertStringNotContainsString('2026-03-01', (string) json_encode($this->body($response)));
    }

    /**
     * Proves only application-approved validation details reach a public problem document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishesOnlyApplicationSafeValidationViolations(): void
    {
        $response = $this->responder()->problem(new BusinessRecordValidationFailed([
            new ValidationViolation('amount', 'invalid_decimal', 'The exact decimal is invalid.'),
            new ValidationViolation('record', 'field_access', 'One or more submitted fields are unavailable.'),
        ]), self::INSTANCE);
        $body = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('amount', $body['violations'][0]['field']);
        self::assertSame('record', $body['violations'][1]['field']);
        self::assertCount(2, $body['violations']);
    }

    /**
     * Proves query and schema internals are redacted while transient failures remain retryable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDoesNotPublishCompilerDetailsAndMarksTemporaryFailuresRetryable(): void
    {
        $invalid = $this->responder()->problem(
            new InvalidBusinessRecordQuery('secret_margin is not filterable.'),
            self::INSTANCE,
        );
        $unavailable = $this->responder()->problem(
            new BusinessRecordSchemaUnavailable('table secret_records is missing.'),
            self::INSTANCE,
        );

        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringNotContainsString('secret_margin', (string) $invalid->getBody());
        self::assertSame(503, $unavailable->getStatusCode());
        self::assertSame('1', $unavailable->getHeaderLine('Retry-After'));
        self::assertStringNotContainsString('secret_records', (string) $unavailable->getBody());
    }

    /**
     * Proves arbitrary invalid-argument messages from trusted custom handlers never reach REST callers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDoesNotPublishArbitraryInvalidArgumentMessages(): void
    {
        $response = $this->responder()->problem(
            new InvalidArgumentException('Extension secret: database-password=do-not-publish'),
            self::INSTANCE,
        );
        $body = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('The business-record request is invalid.', $body['detail']);
        self::assertStringNotContainsString('database-password', (string) $response->getBody());
        self::assertStringNotContainsString('do-not-publish', (string) $response->getBody());
    }

    /**
     * Decode one JSON response body for exact assertions.
     *
     * @param   ResponseInterface  $response  Response carrying one JSON object.
     *
     * @return  array<string, mixed>  Decoded response document.
     *
     * @since   2.0.0
     */
    private function body(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);

        return $body;
    }

    /**
     * Construct the real responder and its shared safe projector.
     *
     * @return  BusinessRecordApiResponder  Responder under test.
     *
     * @since   2.0.0
     */
    private function responder(): BusinessRecordApiResponder
    {
        return new BusinessRecordApiResponder(
            new BusinessRecordApiPresenter(new BusinessRecordProjector()),
            new ProblemDetailsResponseFactory(),
        );
    }
}
