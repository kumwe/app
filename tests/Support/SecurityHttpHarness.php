<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Administrator\Http\Handler\AdministratorLoginHandler;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Mezzio\Application;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Drives the real HTTP pipeline the way an attacker reaches it, for the P7-C security qualification.
 *
 * Every request enters through the Mezzio application the production container composes, so trusted-host,
 * body-limit, security-header, session, CSRF, bearer, idempotency and authorization middleware all run in
 * their production order. Credentials are obtained only through production paths: the administrator signs
 * in through the login form, and machine actors are real users whose tokens the administrator issues with
 * the ordinary access-control service. Nothing here constructs a principal by reflection.
 *
 * @since  2.0.0
 */
final class SecurityHttpHarness
{
    /**
     * Origin every request is addressed to; it matches the test deployment's trusted host.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ORIGIN = 'https://kumwe.test';

    /**
     * Browser user agent every session in the harness is bound to.
     *
     * @var    string
     * @since  2.0.0
     */
    public const USER_AGENT = 'Kumwe security qualification browser';

    /**
     * Records written to the application logger since `recordLogs()` was called, or null before it.
     *
     * @var    TestHandler|null
     * @since  2.0.0
     */
    private ?TestHandler $logs = null;

    /**
     * Keep the booted container and the pipeline it composed.
     *
     * @param  Container    $container    Production container migrated against the suite database.
     * @param  Application  $application  Mezzio pipeline that container composed.
     *
     * @since  2.0.0
     */
    private function __construct(public readonly Container $container, public readonly Application $application)
    {
    }

    /**
     * Boot the production kernel against the suite database.
     *
     * @return  self  Harness bound to a fresh container.
     *
     * @throws  RuntimeException  When the container does not compose a Mezzio application.
     *
     * @since   2.0.0
     */
    public static function boot(): self
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        TestKernelFactory::administratorContext($container);
        $application = $container->get(Application::class);
        if (!$application instanceof Application) {
            throw new RuntimeException('The container did not compose the HTTP application.');
        }

        return new self($container, $application);
    }

    /**
     * Build a request on the trusted origin carrying the harness browser's user agent.
     *
     * The query string is parsed into the query parameters exactly as the SAPI populates `$_GET`, because a
     * factory-built request otherwise carries the query only in its URI and handlers read the parameters.
     *
     * @param   string  $method  HTTP method.
     * @param   string  $path    Absolute path, optionally with a query string.
     *
     * @return  ServerRequestInterface  Request ready for further headers, cookies or a body.
     *
     * @since   2.0.0
     */
    public function request(string $method, string $path): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, self::ORIGIN . $path)
            ->withHeader('Host', 'kumwe.test')
            ->withHeader('User-Agent', self::USER_AGENT);
        $query = parse_url(self::ORIGIN . $path, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $parameters);
            $request = $request->withQueryParams($parameters);
        }

        return $request;
    }

    /**
     * Send one request through the complete pipeline.
     *
     * @param   ServerRequestInterface  $request  Request to dispatch.
     *
     * @return  ResponseInterface  Whatever the pipeline answered.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->application->handle($request);
    }

    /**
     * Sign the bootstrapped administrator in through the login form and return the session cookie.
     *
     * @return  array<string, string>  Cookie parameters that carry the administrator session.
     *
     * @throws  RuntimeException  When the form or the sign-in does not behave as production does.
     *
     * @since   2.0.0
     */
    public function administratorCookie(): array
    {
        $form = $this->handle($this->request('GET', '/administrator/login'));
        if (preg_match('/name="_csrf" value="([^"]+)"/', (string) $form->getBody(), $field) !== 1) {
            throw new RuntimeException('The sign-in form carries no request-forgery token.');
        }
        $signedIn = $this->handle(
            $this->request('POST', '/administrator/login')
                ->withCookieParams([AdministratorLoginHandler::LOGIN_CSRF_COOKIE_NAME => $field[1]])
                ->withParsedBody([
                    'email' => TestKernelFactory::ADMINISTRATOR_EMAIL,
                    'password' => TestKernelFactory::ADMINISTRATOR_PASSWORD,
                    '_csrf' => $field[1],
                ]),
        );
        $cookie = $signedIn->getHeader('Set-Cookie')[0] ?? '';
        if (preg_match('/^kumwe_administrator=([^;]+);/', $cookie, $value) !== 1) {
            throw new RuntimeException('The administrator sign-in issued no session cookie.');
        }

        return ['kumwe_administrator' => $value[1]];
    }

    /**
     * Create a real user holding exactly the given capabilities and issue it a bearer token.
     *
     * @param   list<string>  $capabilities  Capabilities the user's role grants and the token carries.
     * @param   string        $audience      Token audience, `kumwe-http` for REST or `kumwe-mcp` for MCP.
     * @param   string        $purpose       Token purpose, `api` for REST or `mcp` for MCP.
     *
     * @return  array{token: string, subject: string, email: string, grants: array<string, string>}  Plaintext
     *          token, user UUID, address, and the grant UUID of each capability for revocation tests.
     *
     * @throws  RuntimeException  When the access-control services are unavailable.
     *
     * @since   2.0.0
     */
    public function machineActor(array $capabilities, string $audience = 'kumwe-http', string $purpose = 'api'): array
    {
        $access = $this->container->get(AccessControlService::class);
        $identities = $this->container->get(AdministratorIdentityGateway::class);
        if (!$access instanceof AccessControlService || !$identities instanceof AdministratorIdentityGateway) {
            throw new RuntimeException('The access-control services are unavailable.');
        }
        $administrator = TestKernelFactory::administratorContext($this->container);
        $marker = str_replace('-', '', Uuid::uuid7()->toString());
        $email = sprintf('security-%s@example.test', $marker);
        $subject = $access->createUser($administrator, $email, 'Security qualification actor', 'correct horse battery');
        $role = $access->createRole($administrator, 'security-' . $marker, 'Security qualification role');
        $grants = [];
        foreach ($capabilities as $capability) {
            $grants[$capability] = $access->grant($administrator, $role, $capability);
        }
        $access->assignRole($administrator, $subject, $role);
        $issued = $identities->issueAccessToken(
            $administrator,
            $email,
            'Security qualification ' . $audience,
            $capabilities,
            null,
            $audience,
            $purpose,
        );

        return ['token' => $issued['token'], 'subject' => $subject, 'email' => $email, 'grants' => $grants];
    }

    /**
     * Build a bearer-authenticated JSON request for the default site.
     *
     * @param   string                     $method   HTTP method.
     * @param   string                     $path     Absolute path, optionally with a query string.
     * @param   string                     $token    Plaintext bearer token.
     * @param   array<mixed, mixed>|null   $body     JSON body, or null for none.
     * @param   array<string, string>      $headers  Additional headers, such as `Idempotency-Key`.
     *
     * @return  ServerRequestInterface  Request ready to dispatch.
     *
     * @since   2.0.0
     */
    public function api(
        string $method,
        string $path,
        string $token,
        ?array $body = null,
        array $headers = [],
    ): ServerRequestInterface {
        $request = $this->request($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader(BearerAuthenticationMiddleware::SITE_HEADER, 'default')
            ->withHeader('Accept', 'application/json');
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Open an initialized MCP session over the Streamable HTTP route for an MCP-audience token.
     *
     * @param   string  $token  Plaintext token issued with the `kumwe-mcp` audience and `mcp` purpose.
     *
     * @return  string  The session identifier the server assigned.
     *
     * @throws  RuntimeException  When the server does not complete the initialization handshake.
     *
     * @since   2.0.0
     */
    public function mcpSession(string $token): string
    {
        $initialize = $this->handle($this->mcpRequest($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'kumwe-security-qualification', 'version' => '1'],
            ],
        ]));
        $session = $initialize->getHeaderLine('Mcp-Session-Id');
        if ($initialize->getStatusCode() !== 200 || $session === '') {
            throw new RuntimeException('The MCP server did not initialize a session.');
        }
        $initialized = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];
        $this->handle($this->mcpRequest($token, $initialized, $session));

        return $session;
    }

    /**
     * Call one MCP tool inside an initialized session and decode the JSON-RPC answer.
     *
     * @param   string               $token      Plaintext MCP-audience token.
     * @param   string               $session    Session identifier from `mcpSession()`.
     * @param   string               $tool       Tool name.
     * @param   array<string, mixed>  $arguments  Tool arguments.
     *
     * @return  array<mixed, mixed>  Decoded JSON-RPC response document.
     *
     * @throws  \JsonException  When the server answers with something other than JSON.
     *
     * @since   2.0.0
     */
    public function mcpCall(string $token, string $session, string $tool, array $arguments): array
    {
        $response = $this->handle($this->mcpRequest($token, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], $session));
        $decoded = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build one MCP JSON-RPC request for the default site.
     *
     * @param   string               $token    Plaintext MCP-audience token.
     * @param   array<string, mixed>  $message  JSON-RPC message.
     * @param   string|null          $session  Session identifier, or null before initialization.
     *
     * @return  ServerRequestInterface  Request ready to dispatch.
     *
     * @since   2.0.0
     */
    public function mcpRequest(string $token, array $message, ?string $session = null): ServerRequestInterface
    {
        $request = $this->api('POST', '/mcp', $token, $message)
            ->withHeader('Accept', 'application/json, text/event-stream');

        return $session === null ? $request : $request->withHeader('Mcp-Session-Id', $session);
    }

    /**
     * Run one console command through its production composition and capture what it printed.
     *
     * @param   class-string<Command>  $command    Command class the container composes.
     * @param   list<string>           $arguments  Arguments exactly as `bin/kumwe` would pass them.
     *
     * @return  array{status: int, output: string, errors: string}  Exit status and both output channels.
     *
     * @throws  RuntimeException  When the container does not compose the command.
     *
     * @since   2.0.0
     */
    public function console(string $command, array $arguments): array
    {
        $instance = $this->container->get($command);
        if (!$instance instanceof Command) {
            throw new RuntimeException('The container does not compose the requested command.');
        }
        $output = new class implements Output {
            use TranslatesConsoleOutput;

            /**
             * Lines the command printed to standard output.
             *
             * @var    list<string>
             * @since  2.0.0
             */
            public array $lines = [];

            /**
             * Lines the command printed to standard error.
             *
             * @var    list<string>
             * @since  2.0.0
             */
            public array $errors = [];

            /**
             * Record one standard-output line.
             *
             * @param   string  $message  Printed line.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function line(string $message): void
            {
                $this->lines[] = $message;
            }

            /**
             * Record one standard-error line.
             *
             * @param   string  $message  Printed line.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function error(string $message): void
            {
                $this->errors[] = $message;
            }
        };
        $status = $instance->execute($arguments, $output);

        return [
            'status' => $status,
            'output' => implode("\n", $output->lines),
            'errors' => implode("\n", $output->errors),
        ];
    }

    /**
     * Write a token into an owner-only file, as an operator hands one to `--token-file`.
     *
     * @param   string  $token  Plaintext token.
     *
     * @return  string  Absolute path of the protected file; the caller removes it.
     *
     * @throws  RuntimeException  When the file cannot be written and protected.
     *
     * @since   2.0.0
     */
    public function tokenFile(string $token): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-security-token-');
        if (!is_string($path) || !chmod($path, 0o600) || file_put_contents($path, $token) === false) {
            throw new RuntimeException('The protected token file could not be written.');
        }

        return $path;
    }

    /**
     * Start capturing every record the application logger writes, after its processors have run.
     *
     * @return  TestHandler  Handler that retains the processed records.
     *
     * @throws  RuntimeException  When the container's logger is not the Monolog logger production composes.
     *
     * @since   2.0.0
     */
    public function recordLogs(): TestHandler
    {
        $logger = $this->container->get(LoggerInterface::class);
        if (!$logger instanceof Logger) {
            throw new RuntimeException('The application logger is not the composed Monolog logger.');
        }
        $this->logs ??= new TestHandler();
        $logger->pushHandler($this->logs);

        return $this->logs;
    }

    /**
     * Serialize every captured record the way the production JSON formatter would expose it.
     *
     * Objects in a record's context are encoded through `JsonSerializable`, which is exactly how a payload
     * object that slipped past the processors would reach the log line.
     *
     * @return  string  One JSON document per captured record, newline separated.
     *
     * @throws  \JsonException  When a captured record cannot be encoded.
     *
     * @since   2.0.0
     */
    public function capturedLogText(): string
    {
        $lines = [];
        foreach ($this->logs?->getRecords() ?? [] as $record) {
            $lines[] = json_encode([
                'message' => $record->message,
                'context' => $record->context,
                'extra' => $record->extra,
            ], JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines);
    }
}
