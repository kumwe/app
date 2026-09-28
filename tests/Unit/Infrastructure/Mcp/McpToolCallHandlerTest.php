<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use Kumwe\App\Infrastructure\Mcp\McpToolCallHandler;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Proves the Kumwe tool-call handler is the SDK's own call path with the JSON Schema-faithful validator.
 *
 * A tool whose schema carries the catalogue's ECMA-262 control-character guard and an empty-object argument
 * is executed with the arguments exactly as sent, invalid arguments still receive the SDK's invalid-params
 * error without reaching the tool, and only `tools/call` requests are claimed.
 *
 * @since  2.0.0
 */
#[CoversClass(McpToolCallHandler::class)]
final class McpToolCallHandlerTest extends TestCase
{
    /**
     * Valid calls run the tool; invalid ones are refused before it; other requests are not claimed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testToolCallsAreValidatedAsJsonSchemaAndRunThroughTheSdkPath(): void
    {
        $calls = [];
        $registry = new Registry();
        $registry->registerTool(new Tool(
            'kumwe_probe',
            'Probe',
            [
                'type' => 'object',
                'properties' => [
                    'record' => ['type' => 'string', 'pattern' => '^[^\\u0000-\\u001F\\u007F]+$'],
                    'input' => ['type' => 'object'],
                ],
                'required' => ['record'],
            ],
            'Probe tool.',
            null,
        ), static function (string $record, array $input = []) use (&$calls): array {
            $calls[] = [$record, $input];

            return ['record' => $record];
        });
        $handler = new McpToolCallHandler($registry, new ReferenceHandler(), new NullLogger());
        $session = $this->createStub(SessionInterface::class);

        $accepted = $handler->handle(
            (new CallToolRequest('kumwe_probe', ['record' => 'INV-0001', 'input' => []]))->withId(1),
            $session,
        );
        $refused = $handler->handle(
            (new CallToolRequest('kumwe_probe', ['record' => "INV\x01"]))->withId(2),
            $session,
        );

        self::assertInstanceOf(Response::class, $accepted);
        self::assertSame(1, $accepted->getId());
        self::assertInstanceOf(CallToolResult::class, $accepted->result);
        self::assertFalse($accepted->result->isError);
        self::assertInstanceOf(Error::class, $refused);
        self::assertSame(Error::INVALID_PARAMS, $refused->code);
        self::assertSame([['INV-0001', []]], $calls);
        self::assertTrue($handler->supports(new CallToolRequest('kumwe_probe', [])));
        self::assertFalse($handler->supports(new PingRequest()));
    }
}
