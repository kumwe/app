<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Observability;

use Kumwe\App\Infrastructure\Observability\CorrelationContext;
use Kumwe\App\Infrastructure\Observability\LogContextProcessor;
use Kumwe\App\Infrastructure\Observability\LogRedactionProcessor;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LogContextProcessor::class)]
#[CoversClass(LogRedactionProcessor::class)]
#[CoversClass(CorrelationContext::class)]
final class LoggingContractTest extends TestCase
{
    public function testEveryDeclaredRequiredContextKeyIsPresentOnAPlainRecord(): void
    {
        $contract = self::contract();
        $record = (self::stamp($contract, new CorrelationContext()))(self::record());

        foreach ($contract->requiredContext as $key) {
            self::assertArrayHasKey($key, $record->context, sprintf('%s is declared required.', $key));
        }
        self::assertSame('2.9.0-qualification', $record->context['release']);
        self::assertSame('http', $record->context['runtime']);
        self::assertSame('success', $record->context['outcome']);
        self::assertSame(LogContextProcessor::UNKNOWN, $record->context['correlation_id']);
    }

    public function testAnOpenUnitOfWorkStampsItsIdentifiersAndClosingItStopsThem(): void
    {
        $correlation = new CorrelationContext();
        $processor = self::stamp(self::contract(), $correlation);
        $correlation->begin(
            'request-identifier-one',
            null,
            '4bf92f3577b34da6a3ce929d0e0e4736',
            '00f067aa0ba902b7',
        );
        $inside = $processor(self::record());
        $correlation->end();
        $outside = $processor(self::record());

        self::assertSame('request-identifier-one', $inside->context['request_id']);
        self::assertSame('request-identifier-one', $inside->context['correlation_id']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $inside->context['trace_id']);
        self::assertSame('00f067aa0ba902b7', $inside->context['span_id']);
        self::assertArrayNotHasKey('request_id', $outside->context);
        self::assertSame(LogContextProcessor::UNKNOWN, $outside->context['correlation_id']);
    }

    public function testOutcomeIsDerivedFromSeverityButNeverOverwritesAnExplicitOne(): void
    {
        $processor = self::stamp(self::contract(), new CorrelationContext());
        $failure = $processor(self::record(level: Level::Warning));
        $stated = $processor(self::record(context: ['outcome' => 'deferred'], level: Level::Warning));

        self::assertSame('failure', $failure->context['outcome']);
        self::assertSame('deferred', $stated->context['outcome']);
    }

    public function testDeclaredFieldsLoseTheirValuesAtEveryNestingLevel(): void
    {
        $record = (new LogRedactionProcessor(self::contract()))(self::record(context: [
            'authorization' => 'Bearer patterned-example-header-value',
            'queue' => 'default',
            'headers' => ['set-cookie' => 'session=patterned-example', 'accept' => 'application/json'],
        ]));

        self::assertSame(LogRedactionProcessor::PLACEHOLDER, $record->context['authorization']);
        self::assertSame('default', $record->context['queue']);
        self::assertIsArray($record->context['headers']);
        self::assertSame(LogRedactionProcessor::PLACEHOLDER, $record->context['headers']['set-cookie']);
        self::assertSame('application/json', $record->context['headers']['accept']);
    }

    /**
     * Credentials quoted in the message, a plain string value or a self-serializing object never survive.
     *
     * A configured origin, a failure reason copied out of an exception and a payload object the formatter
     * would expand after every processor had run all reach the log without passing the key rule, so each is
     * scrubbed in its own right while ordinary values stay untouched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCredentialsInTheMessagePlainValuesAndSerializableObjectsAreScrubbed(): void
    {
        $payload = new class implements \JsonSerializable {
            /**
             * Serialize as a payload whose nested key and free text both carry a credential.
             *
             * @return  array<string, mixed>  Serialized payload.
             *
             * @since   2.0.0
             */
            public function jsonSerialize(): array
            {
                $object = 'patterned-example-' . 'object';

                return ['params' => ['password' => $object, 'note' => 'token=patterned-note']];
            }
        };
        $record = new LogRecord(
            new \DateTimeImmutable('2026-08-14T09:00:00+00:00'),
            'kumwe',
            Level::Warning,
            'Fetching https://mirror:patterned-example-message@feed.example.test failed.',
            [
                'origin' => 'https://mirror:patterned-example-origin@feed.example.test/list.json',
                'reason' => 'upstream said secret = patterned-example-reason',
                'payload' => $payload,
                'queue' => 'default',
                'attempt' => 3,
            ],
        );

        $redacted = (new LogRedactionProcessor(self::contract()))($record);
        $encoded = json_encode([$redacted->message, $redacted->context], JSON_THROW_ON_ERROR);

        foreach (['message', 'origin', 'reason', 'object', 'note'] as $stem) {
            self::assertStringNotContainsString('patterned-example-' . $stem, $encoded);
        }
        self::assertStringNotContainsString('patterned-note', $encoded);
        self::assertSame('https://[redacted]@feed.example.test/list.json', $redacted->context['origin']);
        self::assertSame(
            ['params' => ['password' => LogRedactionProcessor::PLACEHOLDER, 'note' => 'token=[redacted]']],
            $redacted->context['payload'],
        );
        self::assertSame('default', $redacted->context['queue']);
        self::assertSame(3, $redacted->context['attempt']);
    }

    public function testAnAttachedExceptionBecomesABoundedSummaryWithNoTraceAndNoDriverCredential(): void
    {
        $failure = new RuntimeException(
            'SQLSTATE[08006] connection to pgsql://reporting-service:patterned-example-passphrase@db:5432 failed',
            0,
            new RuntimeException('token = patterned-example-inner-value'),
        );
        $record = (new LogRedactionProcessor(self::contract()))(self::record(context: ['exception' => $failure]));
        $summary = $record->context['exception'];

        self::assertIsArray($summary);
        self::assertSame(RuntimeException::class, $summary['class']);
        self::assertArrayNotHasKey('trace', $summary);
        self::assertIsString($summary['message']);
        self::assertStringNotContainsString('patterned-example-passphrase', $summary['message']);
        self::assertStringContainsString(LogRedactionProcessor::PLACEHOLDER, $summary['message']);
        self::assertIsArray($summary['previous']);
        self::assertIsString($summary['previous']['message']);
        self::assertStringNotContainsString('patterned-example-inner-value', $summary['previous']['message']);
    }

    /**
     * An over-long exception message is scrubbed before it is cut, so a secret straddling the bound leaks no prefix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnOverLongExceptionMessageIsScrubbedBeforeItIsCutToTheBound(): void
    {
        // The passphrase starts at character 509, so cutting before scrubbing would keep its first letters.
        $message = str_repeat('a', 500) . ' db://u:patterned-example-passphrase@host/reporting';
        $record = (new LogRedactionProcessor(self::contract()))(
            self::record(context: ['exception' => new RuntimeException($message)]),
        );
        $summary = $record->context['exception'];

        self::assertIsArray($summary);
        self::assertIsString($summary['message']);
        self::assertSame(513, mb_strlen($summary['message']));
        self::assertStringEndsWith('…', $summary['message']);
        self::assertStringStartsWith(str_repeat('a', 500) . ' db://[red', $summary['message']);
        self::assertStringNotContainsString('patt', $summary['message']);
    }

    public function testTheWiredLoggerWritesOneJsonLineCarryingTheContractAndNoSecret(): void
    {
        $contract = self::contract();
        $handler = new TestHandler();
        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, true, false));
        $correlation = new CorrelationContext();
        $logger = new Logger('kumwe');
        $logger->pushHandler($handler);
        $logger->pushProcessor(new LogRedactionProcessor($contract));
        $logger->pushProcessor(self::stamp($contract, $correlation));
        $correlation->begin('request-identifier-two');

        $logger->warning('Integration event dispatch failed.', [
            'event_id' => 'patterned-example-event-identifier',
            'password' => 'patterned-example-passphrase',
        ]);

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        $line = $handler->getFormatter()->format($records[0]);
        self::assertIsString($line);
        self::assertStringNotContainsString("\n", rtrim($line, "\n"));
        self::assertStringNotContainsString('patterned-example-passphrase', $line);
        $decoded = json_decode(rtrim($line, "\n"), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['context']);
        self::assertSame('request-identifier-two', $decoded['context']['correlation_id']);
        self::assertSame('failure', $decoded['context']['outcome']);
        self::assertSame(LogRedactionProcessor::PLACEHOLDER, $decoded['context']['password']);
    }

    private static function stamp(ObservabilityContract $contract, CorrelationContext $correlation): LogContextProcessor
    {
        return new LogContextProcessor($contract, $correlation, '2.9.0-qualification', 'http');
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function record(array $context = [], Level $level = Level::Info): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable('2026-08-14T09:00:00+00:00'), 'kumwe', $level, 'Line.', $context);
    }

    private static function contract(): ObservabilityContract
    {
        return ObservabilityContract::load(dirname(__DIR__, 4));
    }
}
