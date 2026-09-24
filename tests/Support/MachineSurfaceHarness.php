<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\ConsoleApplication;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Container;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Mezzio\Application;
use RuntimeException;
use stdClass;

/**
 * Drives one application operation through the real REST kernel, console dispatcher and `/mcp` server.
 *
 * Browser-to-machine equivalence tests use one harness per test so every surface authenticates with its own
 * site-bound token of the right audience and purpose, exactly as an agent would: REST requests pass the bearer,
 * site, idempotency and If-Match middleware; console runs pass the live CLI contract's input grammar with
 * owner-only token files; MCP calls open a Streamable HTTP session and run through the mutation guard. Every
 * token the harness issues is revoked by `cleanup()`, and tokens an interrupted earlier run left behind are
 * revoked when the harness starts, so repeated runs stay under the token quota.
 *
 * @since  2.0.0
 */
final class MachineSurfaceHarness
{
    /**
     * Audience and purpose of each machine surface's credential.
     *
     * @var    array<string, array{string, string}>
     * @since  2.0.0
     */
    private const array CREDENTIALS = [
        'rest' => ['kumwe-http', 'api'],
        'cli' => ['kumwe-cli', 'management'],
        'mcp' => ['kumwe-mcp', 'mcp'],
    ];

    /**
     * Identifiers of the tokens this harness issued.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $tokens = [];

    /**
     * Protected files this harness wrote.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $files = [];

    /**
     * MCP session identifier per token.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $mcpSessions = [];

    /**
     * Next JSON-RPC request identifier.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $sequence = 1;

    /**
     * Bind the harness to one booted kernel and revoke stale tokens a previous run of the same test left.
     *
     * @param  Container  $container  Booted, migrated kernel.
     * @param  string     $prefix     Token-name prefix unique to the calling test class.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly Container $container, private readonly string $prefix)
    {
        $access = $this->access();
        $administrator = TestKernelFactory::administratorContext($container);
        foreach ($access->tokens($administrator) as $token) {
            if (
                is_string($token['id'] ?? null)
                && is_string($token['name'] ?? null)
                && str_starts_with($token['name'], $prefix . '-')
                && ($token['revoked_at'] ?? null) === null
            ) {
                $access->revokeToken($administrator, $token['id']);
            }
        }
    }

    /**
     * Issue one site-bound token for the integration administrator on one machine surface.
     *
     * @param   string        $surface       `rest`, `cli` or `mcp`.
     * @param   list<string>  $capabilities  Delegated capabilities.
     *
     * @return  string  Plaintext token.
     *
     * @throws  RuntimeException  When the surface is unknown.
     *
     * @since   2.0.0
     */
    public function token(string $surface, array $capabilities): string
    {
        [$audience, $purpose] = self::CREDENTIALS[$surface]
            ?? throw new RuntimeException(sprintf('Unknown machine surface "%s".', $surface));
        $identities = $this->container->get(AdministratorIdentityGateway::class);
        if (!$identities instanceof AdministratorIdentityGateway) {
            throw new RuntimeException('The identity gateway is not composed.');
        }
        $issued = $identities->issueAccessToken(
            TestKernelFactory::administratorContext($this->container),
            TestKernelFactory::ADMINISTRATOR_EMAIL,
            $this->prefix . '-' . $surface . '-' . bin2hex(random_bytes(6)),
            $capabilities,
            null,
            $audience,
            $purpose,
        );
        $this->tokens[] = $issued['token_id'];

        return $issued['token'];
    }

    /**
     * Send one REST request through the real HTTP kernel.
     *
     * @param   string                     $token    Plaintext API token.
     * @param   string                     $method   HTTP method.
     * @param   string                     $path     Absolute request path with any query.
     * @param   array<mixed>|string|null   $body     JSON document, raw body, or none.
     * @param   array<string, string>      $headers  Extra headers such as `Idempotency-Key` or `If-Match`.
     *
     * @return  array{status: int, body: mixed, raw: string, headers: array<string, string>}  Response.
     *
     * @since   2.0.0
     */
    public function rest(
        string $token,
        string $method,
        string $path,
        array|string|null $body = null,
        array $headers = [],
    ): array {
        $application = $this->container->get(Application::class);
        if (!$application instanceof Application) {
            throw new RuntimeException('The HTTP application is not composed.');
        }
        $content = is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $body;
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'https://kumwe.test' . $path)
            ->withHeader('Host', 'kumwe.test')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Kumwe-Site', 'default')
            ->withHeader('Accept', 'application/json');
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($content !== null) {
            $request = $request
                ->withHeader('Content-Type', is_array($body) ? 'application/json' : 'application/octet-stream')
                ->withBody((new StreamFactory())->createStream($content));
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $response = $application->handle($request);
        $raw = (string) $response->getBody();
        $flat = [];
        foreach ($response->getHeaders() as $name => $values) {
            $flat[strtolower($name)] = implode(', ', $values);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => $raw === '' ? null : json_decode($raw, true, 64),
            'raw' => $raw,
            'headers' => $flat,
        ];
    }

    /**
     * Run one console command through the dispatcher and the live CLI contract.
     *
     * @param   class-string<Command>  $command    Command service to run.
     * @param   string                 $token      Plaintext CLI management token.
     * @param   list<string>           $arguments  Action and options after the command name.
     *
     * @return  array{status: int, stdout: mixed, stderr: string}  Exit status, decoded stdout and stderr text.
     *
     * @since   2.0.0
     */
    public function cli(string $command, string $token, array $arguments): array
    {
        $instance = $this->container->get($command);
        if (!$instance instanceof Command) {
            throw new RuntimeException(sprintf('%s is not a composed console command.', $command));
        }
        $output = new CapturingMachineConsoleOutput();
        $status = (new ConsoleApplication([$instance], $output))->run([
            'bin/kumwe',
            $instance->name(),
            ...$arguments,
            '--site=default',
            '--token-file=' . $this->protectedFile($token),
        ]);
        $stdout = implode("\n", $output->lines);

        return [
            'status' => $status,
            'stdout' => $stdout === '' ? null : json_decode($stdout, true, 64),
            'stderr' => implode("\n", $output->errors),
        ];
    }

    /**
     * Call one MCP tool through the real `/mcp` Streamable HTTP server behind bearer authentication.
     *
     * @param   string                $token      Plaintext MCP token.
     * @param   string                $tool       Tool name.
     * @param   array<string, mixed>  $arguments  Tool arguments.
     *
     * @return  array{error: bool, value: mixed}  Structured result, or the decoded error envelope.
     *
     * @since   2.0.0
     */
    public function mcp(string $token, string $tool, array $arguments = []): array
    {
        if (!isset($this->mcpSessions[$token])) {
            $this->exchange($token, 'initialize', [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'Kumwe parity harness', 'version' => '1.0.0'],
            ]);
            if (!isset($this->mcpSessions[$token])) {
                throw new RuntimeException('The MCP server did not open a session.');
            }
            $this->exchange($token, 'notifications/initialized', [], true);
        }
        $response = $this->exchange($token, 'tools/call', [
            'name' => $tool,
            'arguments' => $arguments === [] ? new stdClass() : $arguments,
        ]);
        $result = is_array($response) ? ($response['result'] ?? null) : null;
        if (!is_array($result)) {
            throw new RuntimeException('The MCP server answered no tool result: ' . json_encode($response));
        }
        $text = $result['content'][0]['text'] ?? null;
        if (($result['isError'] ?? false) === true) {
            return ['error' => true, 'value' => is_string($text) ? json_decode($text, true, 16) : null];
        }

        return ['error' => false, 'value' => $result['structuredContent'] ?? null];
    }

    /**
     * Write one owner-only protected file, as the console's file-backed inputs require.
     *
     * @param   string  $contents  File contents.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    public function protectedFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-parity-');
        if (!is_string($path) || file_put_contents($path, $contents) === false || !chmod($path, 0o600)) {
            throw new RuntimeException('A protected console input file could not be written.');
        }
        $this->files[] = $path;

        return $path;
    }

    /**
     * Revoke every token and remove every file the harness created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function cleanup(): void
    {
        $access = $this->access();
        $administrator = TestKernelFactory::administratorContext($this->container);
        foreach ($this->tokens as $tokenId) {
            try {
                $access->revokeToken($administrator, $tokenId);
            } catch (\InvalidArgumentException) {
                // A test that revoked its own token already left it in the state cleanup wants.
            }
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tokens = [];
        $this->files = [];
        $this->mcpSessions = [];
    }

    /**
     * Exchange one JSON-RPC message with the MCP endpoint under one token's session.
     *
     * @param   string                $token         Plaintext MCP token.
     * @param   string                $method        JSON-RPC method.
     * @param   array<string, mixed>  $params        Parameters.
     * @param   bool                  $notification  Whether the message carries no identifier.
     *
     * @return  mixed  Decoded response document, or null for an empty body.
     *
     * @since   2.0.0
     */
    private function exchange(string $token, string $method, array $params, bool $notification = false): mixed
    {
        $application = $this->container->get(Application::class);
        if (!$application instanceof Application) {
            throw new RuntimeException('The HTTP application is not composed.');
        }
        $message = ['jsonrpc' => '2.0'];
        if (!$notification) {
            $message['id'] = $this->sequence++;
        }
        $message['method'] = $method;
        if ($params !== []) {
            $message['params'] = $params;
        }
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/mcp')
            ->withHeader('Host', 'kumwe.test')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Kumwe-Site', 'default')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody((new StreamFactory())->createStream(json_encode($message, JSON_THROW_ON_ERROR)));
        if (isset($this->mcpSessions[$token])) {
            $request = $request
                ->withHeader('Mcp-Session-Id', $this->mcpSessions[$token])
                ->withHeader('Mcp-Protocol-Version', '2025-11-25');
        }
        $response = $application->handle($request);
        $session = $response->getHeaderLine('Mcp-Session-Id');
        if ($session !== '') {
            $this->mcpSessions[$token] = $session;
        }
        $body = (string) $response->getBody();

        return $body === '' ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Resolve the access-control service the harness issues and revokes tokens through.
     *
     * @return  AccessControlService  Composed service.
     *
     * @since   2.0.0
     */
    private function access(): AccessControlService
    {
        $access = $this->container->get(AccessControlService::class);
        if (!$access instanceof AccessControlService) {
            throw new RuntimeException('The access-control service is not composed.');
        }

        return $access;
    }
}
