<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Observability;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\App\Administrator\Http\Handler\AdministratorDiagnosticsHandler;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Application\Diagnostics\OperatorDiagnostics;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionObserver;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\OperatorDiagnosticsCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Delivery\Http\Api\Diagnostics\OperatorDiagnosticsApiHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Infrastructure\Mcp\OperatorDiagnosticsMcpHandlers;
use Kumwe\App\Infrastructure\Observability\DoctrineOperatorDiagnostics;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use Kumwe\App\Tests\Support\ScriptedDiagnosticDatabase;
use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use Kumwe\BusinessSchema\Domain\PhysicalNameCompiler;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Twig\Loader\FilesystemLoader;

/**
 * Proves the REST, console and MCP surfaces answer from one reader under one capability and refuse alike.
 *
 * Every surface is composed over the same `DoctrineOperatorDiagnostics` and the deny-by-default gateway, so an
 * operator sees the same document through each, and a caller holding every other capability is refused by each.
 *
 * @since  2.0.0
 */
#[CoversClass(OperatorDiagnosticsApiHandler::class)]
#[CoversClass(OperatorDiagnosticsCommand::class)]
#[CoversClass(OperatorDiagnosticsMcpHandlers::class)]
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(DoctrineOperatorDiagnostics::class)]
#[CoversClass(AdministratorDiagnosticsHandler::class)]
#[UsesClass(AdministratorRenderer::class)]
#[UsesClass(RecoveryAdministratorRenderer::class)]
final class OperatorDiagnosticsSurfacesTest extends TestCase
{
    /**
     * Owner-only token files written by this test.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $files = [];

    /**
     * Remove every token file the test wrote.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    /**
     * REST, console and MCP return the very document the shared reader returns for the same operator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceAnswersWithTheSharedReaderDocument(): void
    {
        $reader = $this->reader();
        $operator = [OperatorDiagnostics::CAPABILITY];
        $expected = $reader->read(AuthorizationContext::human($operator), 'queues');
        self::assertSame('available', $expected['status']);

        $response = (new OperatorDiagnosticsApiHandler($reader, new ProblemDetailsResponseFactory()))
            ->handle($this->request($operator, ['section' => 'queues']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame($expected, json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR));

        $output = new DiagnosticsSurfaceOutput();
        $status = $this->command($reader, $operator)->execute($this->options('--section=queues'), $output);
        self::assertSame(0, $status, implode("\n", $output->errors));
        self::assertSame($expected, json_decode($output->lines[0], true, 16, JSON_THROW_ON_ERROR));

        self::assertSame($expected, $this->mcp($reader, $operator)->readOperatorDiagnostics('queues'));
    }

    /**
     * Every diagnostic state keeps the authenticated administrator shell and its session's logout token.
     *
     * @param   string                                $status   Source availability.
     * @param   ?string                               $reason   Sanitized reason for an unavailable source.
     * @param   list<array<string, int|string|null>>  $rows     Bounded result projection.
     * @param   string                                $message  Visible explanation of this state.
     *
     * @return  void
     */
    #[DataProvider('administratorStates')]
    public function testAdministratorRendersEveryDiagnosticStateInsideItsAuthenticatedShell(
        string $status,
        ?string $reason,
        array $rows,
        string $message,
    ): void {
        $diagnostics = self::createStub(OperatorDiagnostics::class);
        $diagnostics->method('read')->willReturn([
            'section' => 'queues',
            'observed_at' => '2026-09-30T10:00:00+00:00',
            'status' => $status,
            'status_reason' => $reason,
            'statement_timeout_ms' => 1000,
            'statement_limit' => 3,
            'statement_budget_ms' => 3000,
            'row_limit_per_source' => 20,
            'rows' => $rows,
            'source_details' => 'private-database-password-and-sql-text',
        ]);
        $response = (new AdministratorDiagnosticsHandler($diagnostics, $this->renderer()))
            ->handle($this->administratorRequest(['administrator.access', OperatorDiagnostics::CAPABILITY]));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('data-administrator-shell', $body);
        self::assertStringContainsString('<main class="administrator-main" id="administrator-content"', $body);
        self::assertMatchesRegularExpression(
            '~<aside\b[^>]*class="administrator-sidebar"[^>]*>.*?'
            . '<a href="/administrator/diagnostics" aria-current="page">.*?</aside>~s',
            $body,
        );
        self::assertMatchesRegularExpression(
            '~<form action="/administrator/logout" method="post">'
            . '<input type="hidden" name="_csrf" value="diagnostics-csrf-token">~',
            $body,
        );
        self::assertStringNotContainsString('class="login-page"', $body);
        self::assertStringNotContainsString('private-database-password-and-sql-text', $body);
        self::assertStringContainsString($message, $body);
        self::assertStringContainsString('datetime="2026-09-30T10:00:00+00:00"', $body);
        foreach (OperatorDiagnostics::SECTIONS as $section) {
            self::assertStringContainsString('href="/administrator/diagnostics?section=' . $section . '"', $body);
        }
        if ($status === 'available' && $rows !== []) {
            self::assertStringContainsString('<dd>integration-outbox</dd>', $body);
            self::assertStringContainsString('<dd>7</dd>', $body);
            self::assertStringContainsString('<dd>Unknown</dd>', $body);
            self::assertStringNotContainsString('role="status"', $body);
        } else {
            self::assertStringContainsString('role="status"', $body);
            self::assertStringNotContainsString('<dl ', $body);
        }
    }

    /**
     * @return  iterable<string, array{string, ?string, list<array<string, int|string|null>>, string}>
     */
    public static function administratorStates(): iterable
    {
        yield 'populated' => ['available', null, [[
            'source' => 'integration-outbox',
            'depth_lower_bound' => 7,
            'oldest_age_seconds' => null,
        ]], 'Results are bounded samples.'];
        yield 'empty' => ['available', null, [], 'No observations were found in this sample.'];
        yield 'unavailable' => ['unavailable', 'source_unreadable', [], 'This source is unavailable.'];
        yield 'budget exceeded' => [
            'budget_exceeded',
            'time',
            [],
            'The diagnostic exceeded its time or result budget.',
        ];
    }

    /**
     * A caller holding every other operator capability is refused by the reader and by every surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySurfaceRefusesACallerWithoutTheDiagnosticsCapability(): void
    {
        $reader = $this->reader();
        $other = ['system.settings.manage', 'automation.manage', 'audit.manage', 'extensions.manage'];
        $refusals = [
            [AuthorizationDenied::class, fn () => $reader->read(AuthorizationContext::human($other), 'queues')],
            [AuthorizationDenied::class, fn () => (new OperatorDiagnosticsApiHandler(
                $reader,
                new ProblemDetailsResponseFactory(),
            ))->handle($this->request($other, ['section' => 'queues']))],
            [AuthorizationDenied::class, fn () => (new AdministratorDiagnosticsHandler($reader, $this->renderer()))
                ->handle($this->administratorRequest($other))],
            [InsufficientCapability::class, fn () => $this->mcp($reader, $other)->readOperatorDiagnostics()],
            [InvalidArgumentException::class, fn () => McpHandlersFixture::create(new McpCapabilityCatalog())
                ->forContext(AuthorizationContext::human([OperatorDiagnostics::CAPABILITY]))
                ->readOperatorDiagnostics()],
        ];
        foreach ($refusals as $index => [$expected, $call]) {
            try {
                $call();
                self::fail(sprintf('Refusal %d was not raised.', $index));
            } catch (Throwable $refusal) {
                self::assertInstanceOf($expected, $refusal, (string) $index);
            }
        }

        $output = new DiagnosticsSurfaceOutput();
        self::assertSame(1, $this->command($reader, $other)->execute($this->options(), $output));
        self::assertSame([], $output->lines);
        self::assertCount(1, $output->errors);
    }

    /**
     * Malformed and unknown sections are refused before any statement, and an unavailable answer fails the CLI.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSurfacesRefuseMalformedSectionsAndReportUnavailableAnswers(): void
    {
        $operator = [OperatorDiagnostics::CAPABILITY];
        $database = new ScriptedDiagnosticDatabase(new PostgreSQLPlatform(), static fn (): array => []);
        $reader = $this->reader($database);
        $api = new OperatorDiagnosticsApiHandler($reader, new ProblemDetailsResponseFactory());
        foreach ([['section' => 'SELECT secret'], ['section' => ['queues']]] as $query) {
            $response = $api->handle($this->request($operator, $query));
            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('validation-failed', (string) $response->getBody());
        }
        self::assertSame([], $database->statements);

        $output = new DiagnosticsSurfaceOutput();
        self::assertSame(1, $this->command($reader, $operator)->execute($this->options('--section=bogus'), $output));
        self::assertSame(1, $this->command($reader, $operator)->execute(['--section'], $output));
        $this->expectException(InvalidArgumentException::class);
        $this->mcp($reader, $operator)->readOperatorDiagnostics('bogus');
    }

    /**
     * An answer the engine could not give exits the console with failure while still printing the document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheConsoleExitsWithFailureWhenTheSourceIsUnavailable(): void
    {
        $reader = $this->reader(new ScriptedDiagnosticDatabase(
            new PostgreSQLPlatform(),
            static fn (string $sql): mixed => str_starts_with($sql, 'SET LOCAL')
                ? [] : ScriptedDiagnosticDatabase::failure('42501'),
        ));
        $output = new DiagnosticsSurfaceOutput();
        $status = $this->command($reader, [OperatorDiagnostics::CAPABILITY])
            ->execute($this->options('--section=contention'), $output);
        self::assertSame(1, $status);
        $document = json_decode($output->lines[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame('unavailable', $document['status']);
        self::assertSame('source_unreadable', $document['status_reason']);
    }

    /**
     * Build the shared reader over the deny-by-default gateway and a scripted PostgreSQL engine.
     *
     * @param   ?ScriptedDiagnosticDatabase  $database  Engine double; an empty PostgreSQL by default.
     *
     * @return  DoctrineOperatorDiagnostics  Reader every surface composes.
     *
     * @since   2.0.0
     */
    private function reader(?ScriptedDiagnosticDatabase $database = null): DoctrineOperatorDiagnostics
    {
        $connection = ($database ?? new ScriptedDiagnosticDatabase(
            new PostgreSQLPlatform(),
            static fn (): array => [],
        ))->connection();
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-30T10:00:00+00:00'));

        return new DoctrineOperatorDiagnostics(
            $connection,
            new TableNames($connection, 'kumwe_'),
            AuthorizationContext::gateway(),
            self::createStub(RetentionObserver::class),
            RetentionCatalogue::declared(),
            new PhysicalNameCompiler('kumwe_'),
            $clock,
        );
    }

    /**
     * Build an authenticated REST request whose principal and execution context match.
     *
     * @param   list<string>          $capabilities  Capabilities the credential holds.
     * @param   array<string, mixed>  $query         Query parameters.
     *
     * @return  ServerRequestInterface  Request as the API middleware leaves it.
     *
     * @since   2.0.0
     */
    private function request(array $capabilities, array $query): ServerRequestInterface
    {
        $context = AuthorizationContext::human($capabilities);

        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/diagnostics')
            ->withQueryParams($query)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $context->principal());
    }

    /** @param list<string> $capabilities */
    private function administratorRequest(array $capabilities): ServerRequestInterface
    {
        $context = AuthorizationContext::human($capabilities);
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            $context->principal(),
            'diagnostics-csrf-token',
            new DateTimeImmutable('2026-09-30T11:00:00+00:00'),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/diagnostics')
            ->withQueryParams(['section' => 'queues'])
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session);
    }

    private function renderer(): AdministratorRenderer
    {
        $root = dirname(__DIR__, 4);
        $loader = new FilesystemLoader($root . '/templates/administrator');
        $loader->addPath($root . '/templates/interface-standard', 'kis');
        $twig = new AdministratorTwigEnvironment($loader, ['strict_variables' => true]);
        $twig->addExtension(InterfaceTranslation::twigExtension());

        return new AdministratorRenderer(
            $twig,
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new FilesystemLoader())),
            new DeterministicCanonicalEncoder(),
        );
    }

    /**
     * Build the console command whose token resolves to a principal holding the given capabilities.
     *
     * @param   DoctrineOperatorDiagnostics  $reader        Shared reader.
     * @param   list<string>                 $capabilities  Capabilities the token carries.
     *
     * @return  OperatorDiagnosticsCommand  Command under test.
     *
     * @since   2.0.0
     */
    private function command(DoctrineOperatorDiagnostics $reader, array $capabilities): OperatorDiagnosticsCommand
    {
        return new OperatorDiagnosticsCommand($reader, new ConsoleAuthorizer(new class ($capabilities) implements
            AccessTokenVerifier
        {
            /**
             * Keep the capabilities the token resolves to.
             *
             * @param  list<string>  $capabilities  Token authority.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly array $capabilities)
            {
            }

            /**
             * Resolve every token to the configured principal.
             *
             * @param   string  $token           Token text.
             * @param   string  $audience        Required audience.
             * @param   string  $purpose         Required purpose.
             * @param   string  $siteIdentifier  Site the token must belong to.
             *
             * @return  ?AuthenticatedPrincipal  Principal holding the configured capabilities.
             *
             * @since   2.0.0
             */
            public function verify(
                string $token,
                string $audience = 'kumwe-http',
                string $purpose = 'api',
                string $siteIdentifier = 'default',
            ): ?AuthenticatedPrincipal {
                return AuthorizationContext::principal($this->capabilities);
            }
        }));
    }

    /**
     * Build MCP handlers composing the shared reader for a credential holding the given capabilities.
     *
     * @param   DoctrineOperatorDiagnostics  $reader        Shared reader.
     * @param   list<string>                 $capabilities  Credential authority.
     *
     * @return  KumweMcpHandlers  Handlers bound to that credential.
     *
     * @since   2.0.0
     */
    private function mcp(DoctrineOperatorDiagnostics $reader, array $capabilities): KumweMcpHandlers
    {
        return McpHandlersFixture::create(
            new McpCapabilityCatalog(),
            diagnostics: new OperatorDiagnosticsMcpHandlers($reader),
        )->forContext(AuthorizationContext::human($capabilities));
    }

    /**
     * Console options naming the default site and an owner-only token file.
     *
     * @param   string  ...$extra  Further options.
     *
     * @return  list<string>  Command arguments.
     *
     * @since   2.0.0
     */
    private function options(string ...$extra): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-diagnostics-token-');
        self::assertIsString($path);
        file_put_contents($path, 'console-token');
        chmod($path, 0o600);
        $this->files[] = $path;

        return ['--site=default', '--token-file=' . $path, ...array_values($extra)];
    }
}

/**
 * Output double that keeps result and failure lines apart, as the console streams do.
 *
 * @since  2.0.0
 */
final class DiagnosticsSurfaceOutput implements Output
{
    use TranslatesConsoleOutput;

    /**
     * Result lines the command wrote.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $lines = [];

    /**
     * Failure lines the command wrote.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $errors = [];

    /**
     * Capture one result line.
     *
     * @param   string  $message  Text the command produced.
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
     * Capture one failure line.
     *
     * @param   string  $message  Text the command produced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
