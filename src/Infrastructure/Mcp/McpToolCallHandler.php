<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Answers `tools/call` with the SDK's own handler, validating arguments through `McpEcmaPatternSchemaValidator`.
 *
 * The SDK builder registers its default `CallToolHandler` with the stock validator and offers no way to replace
 * the validator, but consults handlers added with `addRequestHandler()` first. This handler is that addition:
 * lookup, argument binding, result formatting and error answers are the SDK's unchanged `CallToolHandler`; only
 * the validator differs. The response is re-typed to the builder's `RequestHandlerInterface<mixed>` contract
 * without being altered.
 *
 * @implements RequestHandlerInterface<mixed>
 *
 * @since  2.0.0
 */
final readonly class McpToolCallHandler implements RequestHandlerInterface
{
    /**
     * SDK handler that performs the call.
     *
     * @var    CallToolHandler
     * @since  2.0.0
     */
    private CallToolHandler $delegate;

    /**
     * Build the SDK handler over the server's registry and reference handler.
     *
     * @param  RegistryInterface          $registry    Registry the server's tools are registered in.
     * @param  ReferenceHandlerInterface  $references  Reference handler the server executes tools through.
     * @param  LoggerInterface            $logger      Records validation and execution failures.
     *
     * @since  2.0.0
     */
    public function __construct(
        RegistryInterface $registry,
        ReferenceHandlerInterface $references,
        LoggerInterface $logger,
    ) {
        $this->delegate = new CallToolHandler(
            $registry,
            $references,
            $logger,
            new McpEcmaPatternSchemaValidator($logger),
        );
    }

    /**
     * Whether the request is a `tools/call` request.
     *
     * @param   Request  $request  Incoming JSON-RPC request.
     *
     * @return  bool  True for tool calls only.
     *
     * @since   2.0.0
     */
    public function supports(Request $request): bool
    {
        return $this->delegate->supports($request);
    }

    /**
     * Run one tool call through the SDK handler.
     *
     * @param   Request           $request  Tool-call request.
     * @param   SessionInterface  $session  MCP session the call belongs to.
     *
     * @return  Response<mixed>|Error  The SDK handler's answer, unchanged.
     *
     * @since   2.0.0
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $answer = $this->delegate->handle($request, $session);

        return $answer instanceof Error ? $answer : self::response($answer->id, $answer->result);
    }

    /**
     * Carry one result into a response of the builder's handler contract.
     *
     * @param   string|int  $id      JSON-RPC request identifier.
     * @param   mixed       $result  Tool-call result.
     *
     * @return  Response<mixed>  Response with the same identifier and result.
     *
     * @since   2.0.0
     */
    private static function response(string|int $id, mixed $result): Response
    {
        return new Response($id, $result);
    }
}
