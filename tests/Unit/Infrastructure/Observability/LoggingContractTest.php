<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Observability;

use Kumwe\CMS\Infrastructure\Observability\CorrelationContext;
use Kumwe\CMS\Infrastructure\Observability\LogContextProcessor;
use Kumwe\CMS\Infrastructure\Observability\LogRedactionProcessor;
use Kumwe\CMS\Infrastructure\Observability\ObservabilityContract;
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
