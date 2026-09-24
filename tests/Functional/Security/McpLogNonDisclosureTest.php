<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Security;

use Kumwe\App\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\App\Infrastructure\Mcp\McpProtocolLogRedactor;
use Kumwe\App\Tests\Support\SecurityHttpHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pins that an MCP tool call's arguments never reach the application log channel.
 *
 * The protocol SDK logs every inbound JSON-RPC message at the application's default `info` level, once as
 * raw text and once as the decoded request. Before the redactor existed both lines carried the complete
 * tool arguments — record values, content bodies, personal data — into a log whose readers hold none of
 * the tool's authority. The test drives a real tool call through the production `/mcp` route with a
 * sentinel value in its arguments, captures every record the composed logger writes after its
 * processors, and requires the sentinel to be absent while the protocol method remains observable.
 *
 * @since  2.0.0
 */
#[CoversClass(McpProtocolLogRedactor::class)]
#[CoversClass(KumweMcpServerFactory::class)]
final class McpLogNonDisclosureTest extends TestCase
{
    /**
     * A successful MCP mutation logs its protocol method and size but none of the values it carried.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testToolArgumentsNeverReachTheLogChannel(): void
    {
        $harness = SecurityHttpHarness::boot();
        $actor = $harness->machineActor(['navigation.manage'], 'kumwe-mcp', 'mcp');
        $session = $harness->mcpSession($actor['token']);
        $marker = str_replace('-', '', Uuid::uuid7()->toString());
        $sentinel = 'Confidential payload sentinel ' . $marker;
        $handle = 'security_log_' . bin2hex(random_bytes(6));
        $logs = $harness->recordLogs();

        $result = $harness->mcpCall($actor['token'], $session, 'kumwe_menu_create', [
            'operationId' => 'security-log-' . $marker,
            'handle' => $handle,
            'title' => $sentinel,
        ]);

        self::assertArrayHasKey('result', $result, 'The tool call itself succeeds.');
        self::assertNotTrue($result['result']['isError'] ?? false);
        self::assertStringContainsString($sentinel, json_encode($result, JSON_THROW_ON_ERROR));
        $text = $harness->capturedLogText();
        self::assertStringNotContainsString($sentinel, $text, 'No tool argument reaches any log record.');
        self::assertStringNotContainsString($handle, $text, 'Not even the menu handle is logged.');

        $received = array_values(array_filter(
            $logs->getRecords(),
            static fn ($record): bool => $record->message === 'Received message to process.',
        ));
        self::assertCount(1, $received, 'The inbound message is still observable.');
        $summary = $received[0]->context['message'] ?? null;
        self::assertIsArray($summary);
        self::assertSame('text', $summary['kind'] ?? null);
        self::assertIsInt($summary['bytes'] ?? null);
        $handling = array_values(array_filter(
            $logs->getRecords(),
            static fn ($record): bool => $record->message === 'Handling request.',
        ));
        self::assertCount(1, $handling);
        self::assertSame(['kind' => 'message', 'method' => 'tools/call'], $handling[0]->context['request'] ?? null);
        self::assertIsString($handling[0]->context['request_id'] ?? null, 'Correlation context survives.');
    }
}
