<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use InvalidArgumentException;
use Kumwe\App\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordException;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\App\Infrastructure\Mcp\McpToolErrorEnvelope;
use Kumwe\App\Infrastructure\Mcp\McpToolErrorMapper;
use Kumwe\App\Infrastructure\Mcp\McpToolRefusal;
use Kumwe\App\Infrastructure\Mcp\McpToolErrorVocabulary;
use Kumwe\App\Presentation\Application\StepUpAuthenticationRequired;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves expected refusals become stable redacted tool errors and defects remain protocol failures.
 *
 * @since  2.0.0
 */
#[CoversClass(McpToolErrorEnvelope::class)]
#[CoversClass(McpToolErrorMapper::class)]
#[CoversClass(McpToolRefusal::class)]
#[CoversClass(McpToolErrorVocabulary::class)]
final class McpToolErrorMapperTest extends TestCase
{
    /**
     * Map invalid client input without returning the exception's secret-bearing message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExpectedFailureBecomesAnIsErrorResultWithTheFrozenEnvelope(): void
    {
        $result = (new McpToolErrorMapper())->map(new InvalidArgumentException(
            'Bearer secret-token and /private/path must never appear.',
        ));
        self::assertNotNull($result);
        $wire = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($wire['isError']);
        self::assertSame([
            'schema' => 'kumwe.mcp.tool-error.v1',
            'code' => 'request.invalid',
            'message' => 'The request is invalid for this operation.',
            'retryable' => false,
        ], json_decode($wire['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-token', json_encode($wire, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('/private/path', json_encode($wire, JSON_THROW_ON_ERROR));
    }

    /**
     * Preserve explicit retry guidance from an intentionally modelled MCP refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplicitRefusalCarriesOnlyItsSafeCodeMessageAndRetryGuidance(): void
    {
        $result = (new McpToolErrorMapper())->map(new McpToolRefusal(
            'operation.in_progress',
            'The requested operation is still in progress.',
            true,
        ));
        self::assertNotNull($result);
        $wire = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $envelope = json_decode($wire['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('operation.in_progress', $envelope['code']);
        self::assertTrue($envelope['retryable']);
    }

    /**
     * Refuse to publish an explicit refusal whose safe triple is outside the retained vocabulary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplicitRefusalDriftRemainsAnUnexpectedProtocolFailure(): void
    {
        $mapper = new McpToolErrorMapper();

        self::assertNull($mapper->map(new McpToolRefusal(
            'operation.in_progress',
            'A caller tried to change the retained safe sentence.',
            true,
        )));
        self::assertNull($mapper->map(new McpToolRefusal(
            'operation.unretained',
            'An otherwise safe but unretained refusal.',
        )));
    }

    /**
     * Preserve all four finite business-record idempotency outcomes without exposing their messages.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBusinessRecordIdempotencyStatesUseTheirRetainedVocabularyRows(): void
    {
        $expected = [
            'key_reused' => ['business_record.idempotency_key_reused', false],
            'replay_window_elapsed' => ['business_record.idempotency_replay_window_elapsed', false],
            'in_progress' => ['business_record.idempotency_in_progress', true],
            'corrupt' => ['business_record.idempotency_corrupt', false],
        ];

        foreach ($expected as $state => [$code, $retryable]) {
            $result = (new McpToolErrorMapper())->map(new BusinessRecordIdempotencyConflict($state));
            self::assertNotNull($result);
            $wire = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $envelope = json_decode($wire['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

            self::assertSame($code, $envelope['code']);
            self::assertSame($retryable, $envelope['retryable']);
        }
    }

    /**
     * Reject an undeclared code even when it arrives through the formerly open record exception family.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUndeclaredBusinessRecordCodeCannotEscapeTheFiniteRegistry(): void
    {
        self::assertNull((new McpToolErrorMapper())->map(new BusinessRecordException(
            'business_record.unretained',
            'Private business evidence.',
        )));
    }

    /**
     * Leave an unknown runtime failure for the SDK's generic logged protocol boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnexpectedDefectIsNotFlattenedIntoAClientRefusal(): void
    {
        self::assertNull((new McpToolErrorMapper())->map(new RuntimeException(
            'SQL and infrastructure evidence belongs only in the protected server log.',
        )));
    }

    /**
     * Classify both feature-owned step-up exceptions through their inward application marker.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStepUpRefusalsShareOneLayerSafeMcpClassification(): void
    {
        foreach (
            [
                new HighImpactAuthenticationRequired('Private schema evidence.'),
                new StepUpAuthenticationRequired('Private theme evidence.'),
            ] as $exception
        ) {
            $result = (new McpToolErrorMapper())->map($exception);
            self::assertNotNull($result);
            $wire = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $envelope = json_decode($wire['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('authorization.step_up_required', $envelope['code']);
            self::assertSame(
                'The requested operation requires a fresh human authorization proof.',
                $envelope['message'],
            );
        }
    }
}
