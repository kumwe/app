<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use Kumwe\App\Infrastructure\Mcp\McpProtocolLogRedactor;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\InitializedNotification;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins how the MCP protocol log decorator summarizes each payload shape the SDK hands it.
 *
 * @since  2.0.0
 */
#[CoversClass(McpProtocolLogRedactor::class)]
final class McpProtocolLogRedactorTest extends TestCase
{
    /**
     * Every payload key is summarized by shape, while level, message and other context pass unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryPayloadShapeIsSummarizedAndEverythingElsePassesThrough(): void
    {
        $handler = new TestHandler();
        $logger = new McpProtocolLogRedactor(new Logger('mcp', [$handler]));
        $secret = 'payload value that must not be logged';
        $raw = '{"jsonrpc":"2.0","method":"tools/call","params":{"arguments":{"x":"' . $secret . '"}}}';

        $logger->warning('Protocol event.', [
            'message' => $raw,
            'request' => new InitializedNotification(),
            'response' => new Response(7, ['content' => $secret]),
            'arguments' => ['x' => $secret],
            'structured_content' => null,
            'name' => 'kumwe_menu_create',
            'response_id' => 7,
        ]);
        $logger->info('Nothing to summarize.', ['transport' => 'stdio']);

        $records = $handler->getRecords();
        self::assertCount(2, $records);
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame('Protocol event.', $records[0]->message);
        self::assertSame([
            'message' => ['kind' => 'text', 'bytes' => strlen($raw)],
            'request' => ['kind' => 'message', 'method' => 'notifications/initialized'],
            'response' => ['kind' => 'message'],
            'arguments' => ['kind' => 'array'],
            'structured_content' => ['kind' => 'null'],
            'name' => 'kumwe_menu_create',
            'response_id' => 7,
        ], $records[0]->context);
        self::assertSame(Level::Info, $records[1]->level);
        self::assertSame(['transport' => 'stdio'], $records[1]->context);
        self::assertStringNotContainsString($secret, json_encode(
            array_map(static fn ($record): array => $record->context, $records),
            JSON_THROW_ON_ERROR,
        ));
    }
}
