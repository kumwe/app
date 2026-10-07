<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Diagnostics;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\App\Administrator\Http\Handler\AdministratorDiagnosticsHandler;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Application\Diagnostics\OperatorDiagnostics;
use Kumwe\App\Delivery\Console\Command\OperatorDiagnosticsCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Observability\DoctrineOperatorDiagnostics;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use Kumwe\Automation\JobExecutionClass;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Proves the five operator diagnostics against the real engine this suite runs on.
 *
 * Every test runs unchanged on MariaDB, MySQL and PostgreSQL. The migrated administrator holds
 * `system.diagnostics.read` through the seeded grant; every section answers within its declared bounds or names
 * the fixed reason it could not; a real row-lock wait between two other sessions is observed where this role
 * may read the engine's lock views; statement statistics that are switched off are reported as such rather than
 * as "nothing is slow"; and the administrator screen and console command render the same shared document.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineOperatorDiagnostics::class)]
#[CoversClass(AdministratorDiagnosticsHandler::class)]
#[CoversClass(OperatorDiagnosticsCommand::class)]
final class OperatorDiagnosticsIntegrationTest extends TestCase
{
    /**
     * Queue every seeded job uses, so cleanup cannot touch other work.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string QUEUE = 'diagnostics-probe';

    /**
     * Reasons an answer may give for not answering; anything else is a defect.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array REASONS = ['source_unreadable', 'statement_statistics_off', 'time', 'bytes'];

    /**
     * Kernel under test.
     *
     * @var    Container
     * @since  2.0.0
     */
    private Container $container;

    /**
     * Installation connection.
     *
     * @var    Connection
     * @since  2.0.0
     */
    private Connection $database;

    /**
     * Prefixed table names.
     *
     * @var    TableNames
     * @since  2.0.0
     */
    private TableNames $tables;

    /**
     * Boot the migrated kernel and clear any rows a previous run left behind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->container->get(Connection::class);
        $tables = $this->container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $this->database = $database;
        $this->tables = $tables;
        $this->cleanup();
    }

    /**
     * Remove every row this test seeded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $this->cleanup();
    }

    /**
     * The administrator reads every section within its declared bounds; a caller without the capability cannot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySectionAnswersWithinItsDeclaredBoundsOrNamesWhyNot(): void
    {
        $this->seedJob('pending', '-2 hours');
        $platform = $this->database->getDatabasePlatform();
        $engine = match (true) {
            $platform instanceof PostgreSQLPlatform => 'postgresql',
            $platform instanceof MariaDBPlatform => 'mariadb',
            default => 'mysql',
        };
        foreach (OperatorDiagnostics::SECTIONS as $section) {
            $started = microtime(true);
            $answer = $this->diagnostics()->read($this->administrator(), $section);
            $elapsed = (microtime(true) - $started) * 1000;
            $observation = sprintf('%s returned after %.1f ms.', $section, $elapsed);
            self::assertSame($section, $answer['section']);
            self::assertSame($engine, $answer['engine']);
            self::assertSame(1000, $answer['statement_timeout_ms']);
            self::assertIsInt($answer['statement_limit']);
            self::assertSame($answer['statement_limit'] * 1000, $answer['statement_budget_ms'], $observation);
            self::assertIsArray($answer['rows']);
            if ($answer['status'] === 'available') {
                self::assertNull($answer['status_reason'], $observation);
            } else {
                self::assertContains($answer['status_reason'], self::REASONS, $observation);
                self::assertSame([], $answer['rows']);
            }
            self::assertLessThanOrEqual($section === 'queues' ? 60 : 20, count($answer['rows']), $section);
            foreach ($answer['rows'] as $row) {
                self::assertIsArray($row);
                foreach ($row as $value) {
                    self::assertTrue(is_scalar($value) || $value === null, $section);
                }
            }
        }
        foreach (['queues', 'backlog', 'retention'] as $section) {
            self::assertSame('available', $this->diagnostics()->read($this->administrator(), $section)['status']);
        }

        $this->expectException(AuthorizationDenied::class);
        $this->diagnostics()->read(AuthorizationContext::human(['automation.manage', 'audit.manage']), 'queues');
    }

    /**
     * Queue depth and oldest age come from the durable rows workers claim, grouped by queue name only.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testQueueDepthAndOldestAgeComeFromDurableRows(): void
    {
        $this->seedJob('pending', '-2 hours');
        $this->seedJob('reserved', '-5 minutes');
        $this->seedJob('completed', '-3 hours');

        $answer = $this->diagnostics()->read($this->administrator(), 'queues');
        self::assertIsArray($answer['rows']);
        $rows = array_values(array_filter(
            $answer['rows'],
            static fn (mixed $row): bool => is_array($row) && ($row['stream'] ?? null) === self::QUEUE,
        ));
        self::assertCount(1, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame('jobs', $rows[0]['source']);
        self::assertEquals(2, $rows[0]['depth_lower_bound']);
        self::assertIsInt($rows[0]['oldest_age_seconds']);
        self::assertGreaterThan(7000, $rows[0]['oldest_age_seconds']);
        self::assertLessThan(7400, $rows[0]['oldest_age_seconds']);
    }

    /**
     * "Where is contention" observes a real row-lock wait held between two other database sessions.
     *
     * One child process locks a seeded job row and holds its transaction; a second tries to update the same row
     * and blocks. Where this database role may read the engine's lock views, the wait is attributed to the jobs
     * table; where it may not, the answer says the source is unreadable rather than reporting no contention.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContentionObservesARealRowLockWaitOrNamesTheUnreadableSource(): void
    {
        $job = $this->seedJob('pending', '-1 minute');
        $table = $this->tables->quoted('jobs');
        $holder = $this->session(sprintf("SELECT id FROM %s WHERE id = '%s' FOR UPDATE", $table, $job), 6);
        usleep(700_000);
        $waiter = $this->session(sprintf("UPDATE %s SET priority = 1 WHERE id = '%s'", $table, $job), 0);
        $observed = null;
        try {
            for ($attempt = 0; $attempt < 30 && $observed === null; $attempt++) {
                usleep(150_000);
                $answer = $this->diagnostics()->read($this->administrator(), 'contention');
                if ($answer['status'] !== 'available') {
                    $observed = $answer;
                    break;
                }
                self::assertIsArray($answer['rows']);
                foreach ($answer['rows'] as $row) {
                    if (
                        is_array($row) && is_string($row['table_name'] ?? null)
                        && str_contains($row['table_name'], $this->tables->raw('jobs'))
                    ) {
                        $observed = $answer;
                    }
                }
            }
        } finally {
            foreach ([$holder, $waiter] as $process) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        self::assertIsArray($observed, 'The engine never reported the lock wait.');
        if ($observed['status'] !== 'available') {
            self::assertFalse($this->database->getDatabasePlatform() instanceof PostgreSQLPlatform);
            self::assertSame('unavailable', $observed['status']);
            self::assertContains($observed['status_reason'], ['source_unreadable', 'statement_statistics_off']);
        }
    }

    /**
     * "What is slow" answers only while the engine collects statement statistics and says so when it does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSlowAnswersOnlyWhileTheEngineCollectsStatementStatistics(): void
    {
        $collecting = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? (int) $this->database->fetchOne(
                "SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_stat_statements'",
            ) > 0
            : (int) $this->database->fetchOne('SELECT @@performance_schema') === 1;
        $answer = $this->diagnostics()->read($this->administrator(), 'slow');
        if ($collecting) {
            self::assertNotSame('statement_statistics_off', $answer['status_reason']);
        } else {
            self::assertSame('unavailable', $answer['status']);
            self::assertSame('statement_statistics_off', $answer['status_reason']);
        }
        self::assertSame(
            $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform ? 3 : 4,
            $answer['statement_limit'],
        );
    }

    /**
     * The administrator screen renders the shared document with its declared cost and without caching.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAdministratorScreenRendersTheSharedDocument(): void
    {
        $handler = $this->container->get(AdministratorDiagnosticsHandler::class);
        self::assertInstanceOf(AdministratorDiagnosticsHandler::class, $handler);
        foreach (OperatorDiagnostics::SECTIONS as $section) {
            $request = $this->request($section);
            $session = $request->getAttribute(AdministratorSession::REQUEST_ATTRIBUTE);
            self::assertInstanceOf(AdministratorSession::class, $session);
            $page = $handler->handle($request);
            $html = (string) $page->getBody();
            self::assertSame(200, $page->getStatusCode());
            self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('data-kis-surface="core.administrator.diagnostics"', $html);
            self::assertStringContainsString('Statement limit:', $html, $section);
            $document = new DOMDocument();
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            foreach ([
                '//*[@data-administrator-shell]',
                '//aside[@class="administrator-sidebar"]',
                '//header[@class="administrator-topbar"]',
                '//main[@class="administrator-main"]/*[@class="administrator-content"]',
                '//aside//a[@href="/administrator/diagnostics" and @aria-current="page"]',
            ] as $selector) {
                self::assertSame(1.0, $xpath->evaluate('count(' . $selector . ')'), $section . ': ' . $selector);
            }
            self::assertSame(0.0, $xpath->evaluate('count(//main[@class="login-page"])'), $section);
            self::assertSame($session->csrfToken, $xpath->evaluate(
                'string(//header//form[@action="/administrator/logout"]//input[@name="_csrf"]/@value)',
            ), $section);
        }
    }

    /**
     * The console command answers from the container with a real management token and refuses without one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheConsoleCommandAnswersWithARealManagementToken(): void
    {
        $command = $this->container->get(OperatorDiagnosticsCommand::class);
        $identities = $this->container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(OperatorDiagnosticsCommand::class, $command);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        $this->seedJob('pending', '-1 hour');
        $outputs = [];
        foreach ([[OperatorDiagnostics::CAPABILITY], ['automation.manage']] as $capabilities) {
            $issued = $identities->issueAccessToken(
                $this->administrator(),
                TestKernelFactory::ADMINISTRATOR_EMAIL,
                'Diagnostics console proof',
                $capabilities,
                null,
                'kumwe-cli',
                'management',
            );
            $file = tempnam(sys_get_temp_dir(), 'kumwe-diagnostics-token-');
            self::assertIsString($file);
            file_put_contents($file, $issued['token']);
            chmod($file, 0o600);
            $output = new OperatorDiagnosticsIntegrationOutput();
            try {
                $status = $command->execute(['--site=default', '--token-file=' . $file, '--section=queues'], $output);
            } finally {
                unlink($file);
            }
            $outputs[] = [$status, $output];
        }

        [$status, $output] = $outputs[0];
        self::assertSame(0, $status, implode("\n", $output->errors));
        $document = json_decode($output->lines[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame('queues', $document['section']);
        self::assertSame('available', $document['status']);
        self::assertStringContainsString(self::QUEUE, $output->lines[0]);

        [$status, $output] = $outputs[1];
        self::assertSame(1, $status);
        self::assertSame([], $output->lines);
    }

    /**
     * Resolve the composed diagnostics reader.
     *
     * @return  OperatorDiagnostics  The container's reader.
     *
     * @since   2.0.0
     */
    private function diagnostics(): OperatorDiagnostics
    {
        $diagnostics = $this->container->get(OperatorDiagnostics::class);
        self::assertInstanceOf(OperatorDiagnostics::class, $diagnostics);

        return $diagnostics;
    }

    /**
     * Context of the migrated administrator, who holds the capability through the seeded grant.
     *
     * @return  ExecutionContext  Administrator context.
     *
     * @since   2.0.0
     */
    private function administrator(): ExecutionContext
    {
        return TestKernelFactory::administratorContext($this->container);
    }

    /**
     * Build an authenticated administrator request for the diagnostics screen.
     *
     * @param   string  $section  Section to select.
     *
     * @return  ServerRequestInterface  The request as the administrator middleware leaves it.
     *
     * @since   2.0.0
     */
    private function request(string $section): ServerRequestInterface
    {
        $context = $this->administrator();
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);

        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/diagnostics')
            ->withQueryParams(['section' => $section])
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
                $principal,
                'diagnostics-csrf',
                new DateTimeImmutable('+1 hour'),
            ));
    }

    /**
     * Start a child process holding its own database session that runs one statement in a transaction.
     *
     * @param   string  $statement  Statement run after `BEGIN`.
     * @param   int     $hold       Seconds to keep the transaction open afterwards.
     *
     * @return  resource  The running process.
     *
     * @since   2.0.0
     */
    private function session(string $statement, int $hold)
    {
        $parameters = array_intersect_key(
            $this->database->getParams(),
            array_flip(['driver', 'host', 'port', 'user', 'password', 'dbname', 'serverVersion', 'charset']),
        );
        $script = 'require $argv[3];'
            . '$c = Doctrine\DBAL\DriverManager::getConnection(json_decode(stream_get_contents(STDIN), true));'
            . '$c->beginTransaction(); $c->executeStatement($argv[1]); sleep((int) $argv[2]); $c->rollBack();';
        $process = proc_open(
            [PHP_BINARY, '-r', $script, '--', $statement, (string) $hold, dirname(__DIR__, 3) . '/vendor/autoload.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($parameters, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);

        return $process;
    }

    /**
     * Seed a job in the diagnostics queue.
     *
     * @param   string  $status   Job status.
     * @param   string  $created  Relative creation instant.
     *
     * @return  string  The job identifier.
     *
     * @since   2.0.0
     */
    private function seedJob(string $status, string $created): string
    {
        $id = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('now');
        $this->database->insert($this->tables->raw('jobs'), [
            'id' => $id,
            'queue' => self::QUEUE,
            'job_type' => 'diagnostics.probe',
            'schema_version' => 1,
            'payload' => ['probe' => true],
            'priority' => 0,
            'status' => $status,
            'available_at' => $now->modify($created),
            'lease_expires_at' => $status === 'reserved' ? $now->modify('+5 minutes') : null,
            'attempts' => 0,
            'maximum_attempts' => 5,
            'completed_at' => $status === 'completed' ? $now : null,
            'created_at' => $now->modify($created),
            'updated_at' => $now->modify($created),
            'execution_scope' => JobExecutionClass::Installation->value,
        ], [
            'id' => Types::GUID,
            'payload' => Types::JSON,
            'priority' => Types::SMALLINT,
            'schema_version' => Types::INTEGER,
            'attempts' => Types::SMALLINT,
            'maximum_attempts' => Types::SMALLINT,
            'available_at' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);

        return $id;
    }

    /**
     * Delete every row this test seeded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cleanup(): void
    {
        $this->database->delete($this->tables->raw('jobs'), ['queue' => self::QUEUE]);
    }
}

/**
 * Output double that keeps result and failure lines apart, as the console streams do.
 *
 * @since  2.0.0
 */
final class OperatorDiagnosticsIntegrationOutput implements Output
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
