<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Observability;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Observability\CorrelationContext;
use Kumwe\App\Infrastructure\Observability\LogContextProcessor;
use Kumwe\App\Infrastructure\Observability\LogRedactionProcessor;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Infrastructure\Observability\ProcessRuntime;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the nested log frames that carry correlation, causation and trace identifiers across async work.
 *
 * A durable claim opens a frame from the identifiers its row recorded and the settlement leaves it; these
 * cases prove the frames nest and restore, that a re-entered slot replaces rather than stacks, that a frame
 * can only publish identifier keys, and that the process role and the redaction contract hold for every
 * line such a frame stamps.
 *
 * @since  2.0.0
 */
#[CoversClass(CorrelationContext::class)]
#[CoversClass(LogContextProcessor::class)]
#[CoversClass(LogRedactionProcessor::class)]
#[CoversClass(ProcessRuntime::class)]
final class CorrelationFrameTest extends TestCase
{
    /**
     * A claimed job's frame stamps the origin it recorded, and leaving it restores the enclosing process frame.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAJobFrameStampsItsRecordedOriginAndLeavingRestoresTheProcessFrame(): void
    {
        $correlation = new CorrelationContext();
        $processor = self::stamp($correlation);
        $correlation->begin('console-process-identifier');
        $correlation->enter(
            'job',
            'worker-job-0199',
            'request-that-queued-it',
            'request-that-queued-it',
            '4bf92f3577b34da6a3ce929d0e0e4736',
            ['operation' => 'job', 'job_id' => '0199', 'job_type' => 'system.sessions.purge'],
        );
        $inside = $processor(self::record());
        $correlation->leave('job');
        $after = $processor(self::record());

        self::assertTrue($correlation->inside(CorrelationContext::REQUEST_SLOT));
        self::assertFalse($correlation->inside('job'));
        self::assertSame('worker-job-0199', $inside->context['request_id']);
        self::assertSame('request-that-queued-it', $inside->context['correlation_id']);
        self::assertSame('request-that-queued-it', $inside->context['causation_id']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $inside->context['trace_id']);
        self::assertSame('0199', $inside->context['job_id']);
        self::assertSame('job', $inside->context['operation']);
        self::assertSame('console-process-identifier', $after->context['request_id']);
        self::assertSame('console-process-identifier', $after->context['correlation_id']);
        self::assertArrayNotHasKey('causation_id', $after->context);
        self::assertArrayNotHasKey('job_id', $after->context);
    }

    /**
     * Re-entering an open slot replaces it, so an unsettled claim never pins its identity on the next one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReenteringASlotReplacesTheUnsettledFrameInsteadOfStackingIt(): void
    {
        $correlation = new CorrelationContext();
        $correlation->enter('outbox', 'outbox-dispatch-one', 'correlation-one', 'cause-one');
        $correlation->enter('outbox', 'outbox-dispatch-two', 'correlation-two');

        self::assertSame('outbox-dispatch-two', $correlation->requestId());
        self::assertSame('correlation-two', $correlation->correlationId());
        self::assertNull($correlation->causationId());
        self::assertNull($correlation->traceId());

        $correlation->leave('outbox');
        $correlation->leave('outbox');

        self::assertSame([], $correlation->fragment());
        self::assertNull($correlation->requestId());
    }

    /**
     * Leaving an inner frame out of order still restores the frame beneath whichever frame is now innermost.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNestedFramesRestoreTheRequestFrameAndEndClosesEverything(): void
    {
        $correlation = new CorrelationContext();
        $correlation->begin('request-one', null, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb');
        $correlation->enter('schedule', 'scheduler-pass', 'scheduler-pass');
        $correlation->enter('job', 'worker-job-1', 'request-one', null, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        self::assertSame('worker-job-1', $correlation->requestId());
        $correlation->leave('schedule');
        self::assertSame('worker-job-1', $correlation->requestId());
        $correlation->leave('job');
        self::assertSame('request-one', $correlation->requestId());
        self::assertSame('bbbbbbbbbbbbbbbb', $correlation->fragment()['span_id']);

        $correlation->enter('job', 'worker-job-2', 'request-one');
        $correlation->end();

        self::assertSame([], $correlation->fragment());
        self::assertFalse($correlation->inside('job'));
    }

    /**
     * A frame refuses a subject key outside the declared identifier set, so it cannot publish arbitrary data.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFrameRefusesASubjectKeyThatIsNotADeclaredIdentifier(): void
    {
        $correlation = new CorrelationContext();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payload');
        $correlation->enter('job', 'worker-job-1', 'correlation', subject: ['payload' => 'customer record']);
    }

    /**
     * A caller's explicit identifiers win over the frame's, exactly as the required-context stamping promises.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplicitRecordContextWinsOverTheFrame(): void
    {
        $correlation = new CorrelationContext();
        $correlation->enter('outbox', 'outbox-dispatch-1', 'frame-correlation', 'frame-cause');
        $record = self::stamp($correlation)(self::record(context: ['causation_id' => 'explicit-cause']));

        self::assertSame('explicit-cause', $record->context['causation_id']);
        self::assertSame('frame-correlation', $record->context['correlation_id']);
    }

    /**
     * The process role comes from the entry point: web SAPIs are http and long-running commands name their role.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheProcessRoleIsReadFromTheSapiAndTheCommandName(): void
    {
        self::assertSame(ProcessRuntime::HTTP, ProcessRuntime::detect('fpm-fcgi', []));
        self::assertSame(ProcessRuntime::HTTP, ProcessRuntime::detect('cli-server', ['public/index.php']));
        self::assertSame(ProcessRuntime::WORKER, ProcessRuntime::detect('cli', ['bin/kumwe', 'queue:work']));
        self::assertSame(ProcessRuntime::SCHEDULER, ProcessRuntime::detect('cli', ['bin/kumwe', 'schedule:run']));
        self::assertSame(
            ProcessRuntime::INTEGRATION,
            ProcessRuntime::detect('cli', ['bin/kumwe', 'integration:work']),
        );
        self::assertSame(ProcessRuntime::MCP, ProcessRuntime::detect('cli', ['bin/kumwe', 'mcp:serve']));
        self::assertSame(ProcessRuntime::CONSOLE, ProcessRuntime::detect('cli', ['bin/kumwe', 'database:migrate']));
        self::assertSame(ProcessRuntime::CONSOLE, ProcessRuntime::detect('phpdbg', []));
        self::assertCount(6, ProcessRuntime::VALUES);
    }

    /**
     * The message itself is scrubbed, and the declared session, key and credential names lose their values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMessageIsScrubbedAndSessionKeyAndCredentialFieldsAreRedacted(): void
    {
        $redaction = new LogRedactionProcessor(self::contract());
        $record = $redaction(self::record(
            message: 'Connection to mysql://reporter:patterned-example-passphrase@db:3306 failed; password=hunter',
            context: [
                'session_id' => 'patterned-session-identifier',
                'api_key' => 'patterned-api-key',
                'private_key_path' => '/keys/patterned',
                'credential_reference' => 'patterned-credential',
                'queue' => 'default',
            ],
        ));

        self::assertStringNotContainsString('patterned-example-passphrase', $record->message);
        self::assertStringNotContainsString('hunter', $record->message);
        self::assertStringContainsString(LogRedactionProcessor::PLACEHOLDER, $record->message);
        foreach (['session_id', 'api_key', 'private_key_path', 'credential_reference'] as $key) {
            self::assertSame(LogRedactionProcessor::PLACEHOLDER, $record->context[$key], $key);
        }
        self::assertSame('default', $record->context['queue']);
    }

    /**
     * Build the stamping processor against the shipped contract.
     *
     * @param   CorrelationContext  $correlation  Holder the processor reads.
     *
     * @return  LogContextProcessor  Processor stamping `worker` as the runtime.
     *
     * @since   2.0.0
     */
    private static function stamp(CorrelationContext $correlation): LogContextProcessor
    {
        return new LogContextProcessor(self::contract(), $correlation, '2.9.0-qualification', ProcessRuntime::WORKER);
    }

    /**
     * Load the shipped observability contract.
     *
     * @return  ObservabilityContract  The declaration in `config/observability.php`.
     *
     * @since   2.0.0
     */
    private static function contract(): ObservabilityContract
    {
        return ObservabilityContract::load(dirname(__DIR__, 4));
    }

    /**
     * Build a record with the given message and context.
     *
     * @param   string                $message  Record message.
     * @param   array<string, mixed>  $context  Record context.
     *
     * @return  LogRecord  An informational record on the `kumwe` channel.
     *
     * @since   2.0.0
     */
    private static function record(string $message = 'Job completed.', array $context = []): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable('2026-09-24T12:00:00+00:00'),
            'kumwe',
            Level::Info,
            $message,
            $context,
        );
    }
}
