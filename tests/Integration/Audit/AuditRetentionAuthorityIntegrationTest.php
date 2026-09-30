<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Application\Automation\GlobalJobPrincipals;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Audit\Application\AuditAnchorWriter;
use Kumwe\App\Audit\Application\AuditCheckpoint;
use Kumwe\App\Audit\Application\AuditRetentionAuthorityState;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditAppendOnlyGuard;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditRetentionAuthority;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditRetentionGuard;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditRetentionPrincipalSynchronizer;
use Kumwe\App\Audit\Infrastructure\Persistence\DeferredAuditRetentionService;
use Kumwe\App\Audit\Application\AuditRetentionService;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRecorder;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRetentionService;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailExporter;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailVerifier;
use Kumwe\App\Audit\Infrastructure\Storage\FilesystemAuditArchiveStorage;
use Kumwe\App\Audit\Infrastructure\Storage\FilesystemAuditArchiveVerifier;
use Kumwe\App\Audit\Infrastructure\Storage\FilesystemAuditCheckpointStore;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\VerifyAuditTrailCommand;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\AuditRetentionAuthorityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\AuditTamperEvidenceMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\DatabaseConfiguration;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\AllowingAuditAuthorization;
use Kumwe\App\Tests\Support\AuditTamperHarness;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DatabaseAdministrator;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\App\Tests\Support\CapturingMachineConsoleOutput;
use Kumwe\Audit\Domain\AuditAnchorDigest;
use Kumwe\Audit\Domain\AuditEvent;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Attacks the audit trail with real, separately authenticated database principals (finding P7C-20260928-01).
 *
 * Every hostile statement below runs on its own connection, authenticated as a freshly created account that
 * holds only the documented runtime grant: data manipulation on the two audit tables and permission to
 * execute the installation's audit routines. It holds no trigger, routine-definition or schema privilege.
 * The legitimate path runs as a second fresh account holding only the documented retention grant. The
 * accounts are created through the administrative connection `DatabaseAdministrator` opens.
 */
#[CoversClass(AuditRetentionAuthority::class)]
#[CoversClass(AuditRetentionAuthorityMigration::class)]
#[CoversClass(AuditRetentionGuard::class)]
#[CoversClass(AuditAppendOnlyGuard::class)]
#[CoversClass(DoctrineAuditRetentionService::class)]
#[CoversClass(DoctrineAuditTrailVerifier::class)]
#[CoversClass(FilesystemAuditCheckpointStore::class)]
#[CoversClass(VerifyAuditTrailCommand::class)]
#[CoversClass(AuditRetentionPrincipalSynchronizer::class)]
#[CoversClass(DeferredAuditRetentionService::class)]
final class AuditRetentionAuthorityIntegrationTest extends TestCase
{
    private Container $container;

    private Connection $database;

    private TableNames $tables;

    private Connection $administrator;

    private string $scratch;

    private ?string $previousPrincipal;

    /**
     * Whether the test left prune marks whose archives live only in its scratch directory.
     *
     * @var    bool
     * @since  2.0.0
     */
    private bool $resetTrail = false;

    /**
     * Accounts created by the test, dropped again in tear-down.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $accounts = [];

    /**
     * Connections opened as those accounts.
     *
     * @var    list<Connection>
     * @since  2.0.0
     */
    private array $sessions = [];

    protected function setUp(): void
    {
        $this->container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->container->get(Connection::class);
        $tables = $this->container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $this->database = $database;
        $this->tables = $tables;
        $this->administrator = $this->administrativeConnection();
        $this->previousPrincipal = AuditRetentionAuthority::principal($this->database, $this->tables);
        $this->scratch = sys_get_temp_dir() . '/kumwe-audit-authority-' . bin2hex(random_bytes(8));
        mkdir($this->scratch, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }
        if (isset($this->database)) {
            AuditRetentionAuthority::assign($this->database, $this->tables, $this->previousPrincipal);
            if ($this->resetTrail) {
                // The shared trail must not keep prune marks whose archives leave with the scratch root.
                AuditTamperHarness::truncateTrail($this->database, $this->tables);
            }
        }
        foreach ($this->accounts as $account) {
            $this->dropAccount($account);
        }
        if (isset($this->scratch) && is_dir($this->scratch)) {
            $this->remove($this->scratch);
        }
    }

    /**
     * Setting the retention session flag directly, as the runtime principal, authorizes nothing.
     *
     * This is the reviewed attack: the DML account opens the retention window itself and deletes the
     * trail, forges a retention mark to cover the deletion, and tries to erase or rewrite the ledger.
     * Each statement is refused by the database, and the trail still verifies with its guards.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDirectFlagSettingCannotAuthorizeDeletionByTheRuntimePrincipal(): void
    {
        $this->record(3, '-2 hours');
        self::assertIsInt($this->anchors()->anchor($this->context()));
        $retention = $this->createAccount('ret', $this->retentionGrant());
        AuditRetentionAuthority::assign($this->database, $this->tables, $retention['name']);
        $runtime = $this->session($this->createAccount('dml', $this->runtimeGrant()));
        $events = $this->rows('audit_events');
        $anchors = $this->rows('audit_anchors');

        self::assertSame(
            AuditRetentionAuthorityState::Separated,
            AuditRetentionAuthority::state($runtime, $this->tables),
        );
        self::assertFalse(AuditRetentionAuthority::sessionAuthorized($runtime, $this->tables));
        self::assertTrue(
            AuditAppendOnlyGuard::installed($runtime, $this->tables)
                && AuditRetentionGuard::installed($runtime, $this->tables)
                && AuditRetentionAuthority::installed($runtime, $this->tables),
            'The least-privilege runtime principal must still observe every installed guard.',
        );
        self::assertTrue($this->refused($runtime, true, 'DELETE FROM ' . $this->tables->quoted('audit_events')));
        $tail = $this->database->fetchAssociative(
            'SELECT sequence, rolling_digest, digest, to_position, row_count FROM '
            . $this->tables->quoted('audit_anchors') . ' ORDER BY sequence DESC LIMIT 1',
        );
        self::assertIsArray($tail);
        $forged = $this->forgedPrune($tail);
        self::assertTrue($this->refused($runtime, true, static function (Connection $session) use ($forged): void {
            $session->insert($forged['table'], $forged['row']);
        }));
        self::assertTrue($this->refused($runtime, true, 'DELETE FROM ' . $this->tables->quoted('audit_anchors')));
        self::assertTrue($this->refused(
            $runtime,
            true,
            'UPDATE ' . $this->tables->quoted('audit_anchors') . ' SET row_count = 0',
        ));

        self::assertSame($events, $this->rows('audit_events'));
        self::assertSame($anchors, $this->rows('audit_anchors'));
        self::assertTrue($this->verifier(null)->verify($this->context())->guarded());
        AuditRetentionAuthority::assign($this->database, $this->tables, null);
        self::assertSame(
            AuditRetentionAuthorityState::Unassigned,
            AuditRetentionAuthority::state($runtime, $this->tables),
        );
        self::assertTrue($this->refused($runtime, true, 'DELETE FROM ' . $this->tables->quoted('audit_events')));
    }

    /**
     * Complete erasure of trail and ledger is reported against the retained and the operator checkpoint.
     *
     * The runtime principal cannot perform the erasure at all. It is then performed with schema-owner
     * authority, which removes the guards, to show that even the erasure no DML principal can make
     * cannot produce a clean verdict once a checkpoint has been retained outside the database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFullEvidenceErasureCannotProduceACleanVerdict(): void
    {
        $this->record(3, '-2 hours');
        self::assertIsInt($this->anchors()->anchor($this->context()));
        $runtime = $this->session($this->createAccount('dml', $this->runtimeGrant()));
        $store = new FilesystemAuditCheckpointStore($this->scratch . '/checkpoints');
        $clean = $this->verifier($store)->verifyContinuity($this->context());
        self::assertTrue($clean->report->guarded(), $clean->report->firstDivergence?->detail ?? '');
        self::assertInstanceOf(AuditCheckpoint::class, $clean->checkpoint);
        self::assertEquals($clean->checkpoint, $store->retained());
        $operator = $this->scratch . '/operator-checkpoint.json';
        file_put_contents($operator, json_encode(['checkpoint' => $clean->checkpoint->toArray()]));
        chmod($operator, 0600);

        foreach (['audit_events', 'audit_anchors'] as $table) {
            self::assertTrue($this->refused($runtime, true, 'DELETE FROM ' . $this->tables->quoted($table)));
        }
        AuditTamperHarness::truncateTrail($this->database, $this->tables);
        self::assertSame(0, $this->rows('audit_anchors'));

        $retained = $this->verifier($store)->verifyContinuity($this->context());
        self::assertFalse($retained->report->intact());
        self::assertSame('audit.checkpoint.ledger.regressed', $retained->report->firstDivergence?->code);
        self::assertNull($retained->checkpoint);
        $empty = new FilesystemAuditCheckpointStore($this->scratch . '/lost-host');
        $external = $this->verifier($empty)->verifyContinuity(
            $this->context(),
            1000,
            FilesystemAuditCheckpointStore::read($operator),
        );
        self::assertSame('audit.checkpoint.ledger.regressed', $external->report->firstDivergence?->code);
        self::assertStringContainsString('operator checkpoint', $external->report->firstDivergence->detail);
        self::assertTrue(
            $this->verifier($empty)->verify($this->context())->guarded(),
            'Without a retained checkpoint the erased trail alone is indistinguishable from a new one.',
        );

        $output = new CapturingMachineConsoleOutput();
        $token = $this->scratch . '/token';
        file_put_contents($token, 'console-token');
        chmod($token, 0600);
        $command = new VerifyAuditTrailCommand($this->verifier($store), $this->consoleAuthorizer());
        self::assertSame(1, $command->execute(['--site=default', '--token-file=' . $token], $output));
        self::assertStringContainsString('audit.checkpoint.ledger.regressed', $output->errors[0]);
        $command = new VerifyAuditTrailCommand($this->verifier($empty), $this->consoleAuthorizer());
        self::assertSame(1, $command->execute(
            ['--site=default', '--token-file=' . $token, '--checkpoint-file=' . $operator],
            $output,
        ));
        self::assertStringContainsString('audit.checkpoint.ledger.regressed', $output->errors[1]);
    }

    /**
     * Archived retention runs only as the assigned retention principal, and only when it is separated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLegitimateArchivedRetentionRunsOnlyAsTheSeparateRetentionPrincipal(): void
    {
        AuditTamperHarness::truncateTrail($this->database, $this->tables);
        $this->resetTrail = true;
        $this->record(3, '-40 days');
        self::assertIsInt($this->anchors()->anchor($this->context()));
        $runtimeAccount = $this->createAccount('dml', $this->runtimeGrant());
        $retentionAccount = $this->createAccount('ret', $this->retentionGrant());
        $runtime = $this->session($runtimeAccount);
        $retention = $this->session($retentionAccount);
        $before = $this->rows('audit_events');

        foreach (
            [
                'unassigned' => [$retention, $runtime],
                'runtime connection' => [$this->database, $this->database],
                'runtime principal' => [$runtime, $runtime],
            ] as $case => [$session, $observed]
        ) {
            if ($case === 'runtime connection') {
                AuditRetentionAuthority::assign($this->database, $this->tables, $retentionAccount['name']);
            }
            try {
                $this->retention($session, $observed)->prune($this->context(), 30);
                self::fail(sprintf('Retention must refuse on the %s.', $case));
            } catch (\RuntimeException $refusal) {
                self::assertStringContainsString('retention principal', $refusal->getMessage(), $case);
            }
        }
        try {
            $this->retention($retention, $retention)->prune($this->context(), 30);
            self::fail('Retention must refuse when the runtime principal is the retention principal.');
        } catch (\RuntimeException $refusal) {
            self::assertStringContainsString('not_separated', $refusal->getMessage());
        }
        self::assertSame($before, $this->rows('audit_events'));
        self::assertSame(0, $this->prunes());

        $result = $this->retention($retention, $runtime)->prune($this->context(), 30);

        self::assertSame(3, $result->prunedCount);
        self::assertSame(1, $this->prunes());
        self::assertSame(
            $retentionAccount['name'],
            AuditRetentionAuthority::principal($runtime, $this->tables),
        );
        $report = $this->verifier(null)->verify($this->context());
        self::assertTrue($report->guarded(), $report->firstDivergence?->detail ?? '');
    }

    /**
     * A configured retention credential is assigned by migration and used only by the retention pass.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConfiguredRetentionCredentialIsAssignedByMigrationAndUsedOnlyForRetention(): void
    {
        AuditTamperHarness::truncateTrail($this->database, $this->tables);
        $this->resetTrail = true;
        $account = $this->createAccount('ret', $this->retentionGrant());
        putenv('DB_AUDIT_RETENTION_USER=' . $account['name']);
        putenv('DB_AUDIT_RETENTION_PASSWORD=' . $account['password']);
        try {
            $container = TestKernelFactory::create(Environment::fromGlobals());
        } finally {
            putenv('DB_AUDIT_RETENTION_USER');
            putenv('DB_AUDIT_RETENTION_PASSWORD');
        }
        self::assertSame($account['name'], AuditRetentionAuthority::principal($this->database, $this->tables));
        $synchronizer = $container->get(AuditRetentionPrincipalSynchronizer::class);
        self::assertInstanceOf(AuditRetentionPrincipalSynchronizer::class, $synchronizer);
        self::assertFalse($synchronizer->synchronize(), 'An assignment already in force is left alone.');
        AuditTamperHarness::disableGuards($this->database, $this->tables);
        try {
            $synchronizer->synchronize();
            self::fail('A principal must not be assigned while its guards are missing.');
        } catch (\RuntimeException $refusal) {
            self::assertStringContainsString('before its guards are installed', $refusal->getMessage());
        } finally {
            AuditTamperHarness::enableGuards($this->database, $this->tables);
        }
        $retention = $container->get(AuditRetentionService::class);
        self::assertInstanceOf(DeferredAuditRetentionService::class, $retention);
        $runtime = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $runtime);
        $this->record(3, '-40 days');
        self::assertIsInt($this->anchors()->anchor($this->context()));

        if (AuditRetentionAuthority::state($runtime, $this->tables) !== AuditRetentionAuthorityState::Separated) {
            // A PostgreSQL superuser runtime can never be separated from retention authority.
            try {
                $retention->prune($this->context($container), 30);
                self::fail('Retention must refuse while the runtime principal is not separated.');
            } catch (\RuntimeException $refusal) {
                self::assertStringContainsString('not_separated', $refusal->getMessage());
            }
            self::assertSame(0, $this->prunes());

            return;
        }
        self::assertSame(3, $retention->prune($this->context($container), 30)->prunedCount);
        self::assertSame(1, $this->prunes());
    }

    /**
     * Open the administrative connection that creates and drops the test accounts.
     *
     * @return  Connection  Connection allowed to create accounts and grant table privileges.
     *
     * @since   2.0.0
     */
    private function administrativeConnection(): Connection
    {
        $connection = DatabaseAdministrator::open($this->database, $this->settings());
        if ($connection === null) {
            self::markTestSkipped('No administrative database account is available to create test principals.');
        }

        return $connection;
    }

    /**
     * Read the configured database settings.
     *
     * @return  DatabaseConfiguration  The integration database's connection settings.
     *
     * @since   2.0.0
     */
    private function settings(): DatabaseConfiguration
    {
        $environment = Environment::fromGlobals();
        $driver = $environment->string('DB_DRIVER');

        return new DatabaseConfiguration(
            $driver,
            $environment->string('DB_HOST'),
            $environment->positiveInteger('DB_PORT', $driver === 'pgsql' ? 5432 : 3306),
            $environment->string('DB_NAME'),
            $environment->string('DB_USER'),
            $environment->string('DB_PASSWORD'),
            $environment->string('DB_TABLE_PREFIX', 'kumwe_'),
            $environment->string('DB_SSLMODE', 'disable'),
            $environment->string('DB_SERVER_VERSION', $driver === 'pgsql' ? '17' : 'mariadb-12.3.2'),
        );
    }

    /**
     * The documented runtime grant: data manipulation on the audit tables and nothing that alters them.
     *
     * @return  array<string, list<string>>  Privileges by logical table name.
     *
     * @since   2.0.0
     */
    private function runtimeGrant(): array
    {
        return [
            'audit_events' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            'audit_anchors' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        ];
    }

    /**
     * The documented retention grant: read, append and delete trail rows, read and append ledger rows.
     *
     * @return  array<string, list<string>>  Privileges by logical table name.
     *
     * @since   2.0.0
     */
    private function retentionGrant(): array
    {
        return [
            'audit_events' => ['SELECT', 'INSERT', 'DELETE'],
            'audit_anchors' => ['SELECT', 'INSERT'],
        ];
    }

    /**
     * Create a login holding exactly the given table privileges plus execution of the audit routines.
     *
     * @param   string                       $role    Short label for the account name.
     * @param   array<string, list<string>>  $grants  Privileges by logical table name.
     *
     * @return  array{name: string, password: string}  Credentials of the new account.
     *
     * @since   2.0.0
     */
    private function createAccount(string $role, array $grants): array
    {
        $name = 'kumwe_t_' . $role . '_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        $admin = $this->administrator;
        $this->accounts[] = $name;
        if ($admin->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $admin->executeStatement(sprintf('CREATE ROLE %s LOGIN PASSWORD %s', $name, $admin->quote($password)));
            $admin->executeStatement(sprintf('GRANT USAGE ON SCHEMA %s TO %s', $this->schema(), $name));
            foreach ($grants as $table => $privileges) {
                $admin->executeStatement(sprintf(
                    'GRANT %s ON %s.%s TO %s',
                    implode(', ', $privileges),
                    $this->schema(),
                    $this->tables->quoted($table),
                    $name,
                ));
            }
            $sequence = $admin->fetchOne(
                'SELECT pg_get_serial_sequence(?, ?)',
                [$this->schema() . '.' . $this->tables->raw('audit_events'), 'position'],
            );
            if (is_string($sequence)) {
                $admin->executeStatement(sprintf('GRANT USAGE ON SEQUENCE %s TO %s', $sequence, $name));
            }

            return ['name' => $name, 'password' => $password];
        }
        $database = $admin->quoteSingleIdentifier($this->settings()->database);
        $admin->executeStatement(sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY %s", $name, $admin->quote($password)));
        foreach ($grants as $table => $privileges) {
            $admin->executeStatement(sprintf(
                "GRANT %s ON %s.%s TO '%s'@'%%'",
                implode(', ', $privileges),
                $database,
                $this->tables->quoted($table),
                $name,
            ));
        }
        $admin->executeStatement(sprintf("GRANT EXECUTE ON %s.* TO '%s'@'%%'", $database, $name));

        return ['name' => $name, 'password' => $password];
    }

    /**
     * Drop an account the test created.
     *
     * @param   string  $name  Account name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function dropAccount(string $name): void
    {
        if ($this->administrator->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->administrator->executeStatement(sprintf('DROP OWNED BY %s', $name));
            $this->administrator->executeStatement(sprintf('DROP ROLE %s', $name));

            return;
        }
        $this->administrator->executeStatement(sprintf("DROP USER '%s'@'%%'", $name));
    }

    /**
     * Open a connection authenticated as a test account.
     *
     * @param   array{name: string, password: string}  $account  Credentials.
     *
     * @return  Connection  Open connection as that account.
     *
     * @since   2.0.0
     */
    private function session(array $account): Connection
    {
        $settings = $this->settings();
        $session = (new DoctrineConnectionFactory(new DatabaseConfiguration(
            $settings->driver,
            $settings->host,
            $settings->port,
            $settings->database,
            $settings->user,
            $settings->password,
            $settings->tablePrefix,
            $settings->sslMode,
            $settings->serverVersion,
            $account['name'],
            $account['password'],
        )->forAuditRetention()))->create();
        $this->sessions[] = $session;

        return $session;
    }

    /**
     * Run a statement as a session with the retention flag set, reporting whether an audit guard refused it.
     *
     * Only a refusal raised by an audit guard counts: a missing privilege would also fail the statement,
     * but would prove nothing about the guards.
     *
     * @param   Connection                         $session    Session the statement runs as.
     * @param   bool                               $flag       Whether to open the retention flag first.
     * @param   string|callable(Connection): void  $statement  SQL text, or work to run on the session.
     *
     * @return  bool  True when an audit guard refused the work; the transaction is always rolled back.
     *
     * @since   2.0.0
     */
    private function refused(Connection $session, bool $flag, string|callable $statement): bool
    {
        $postgres = $session->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $session->beginTransaction();
        try {
            if ($flag) {
                $session->executeStatement($postgres
                    ? "SET LOCAL kumwe_audit_prune.enabled = '1'"
                    : 'SET @kumwe_audit_prune = 1');
            }
            is_string($statement) ? $session->executeStatement($statement) : $statement($session);
        } catch (\Doctrine\DBAL\Exception $refusal) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote(AuditAppendOnlyGuard::MESSAGE, '/') . '|'
                . preg_quote(AuditRetentionAuthority::MESSAGE, '/') . '/',
                $refusal->getMessage(),
            );

            return true;
        } finally {
            $session->rollBack();
            if (!$postgres) {
                $session->executeStatement('SET @kumwe_audit_prune = NULL');
            }
        }

        return false;
    }

    /**
     * Compose a correctly hashed prune mark claiming the whole anchored trail.
     *
     * @param   array<string, mixed>  $tail  Newest ledger row.
     *
     * @return  array{table: string, row: array<string, mixed>}  Table and row to insert.
     *
     * @since   2.0.0
     */
    private function forgedPrune(array $tail): array
    {
        $encoder = $this->container->get(CanonicalEncoder::class);
        self::assertInstanceOf(CanonicalEncoder::class, $encoder);
        $sequence = (int) $tail['sequence'] + 1;
        $to = (int) $tail['to_position'];
        $checksum = str_repeat('0', 64);
        $at = '2026-09-30 12:00:00';

        return ['table' => $this->tables->raw('audit_anchors'), 'row' => [
            'id' => Uuid::uuid7()->toString(),
            'sequence' => $sequence,
            'kind' => 'prune',
            'from_position' => 1,
            'to_position' => $to,
            'row_count' => (int) $tail['row_count'],
            'rolling_digest' => (string) $tail['rolling_digest'],
            'previous_digest' => (string) $tail['digest'],
            'digest' => AuditAnchorDigest::compute(
                $sequence,
                'prune',
                1,
                $to,
                (int) $tail['row_count'],
                (string) $tail['rolling_digest'],
                (string) $tail['digest'],
                $checksum,
                $at,
                $encoder,
            ),
            'archive_sha256' => $checksum,
            'created_at' => $at,
        ]];
    }

    /**
     * Compose the retention service over one session, judging separation against another.
     *
     * @param   Connection  $session  Connection the pass runs, archives and deletes on.
     * @param   Connection  $runtime  Connection standing for the ordinary runtime principal.
     *
     * @return  DoctrineAuditRetentionService  Retention service with private archives under the scratch root.
     *
     * @since   2.0.0
     */
    private function retention(Connection $session, Connection $runtime): DoctrineAuditRetentionService
    {
        $encoder = $this->container->get(CanonicalEncoder::class);
        $clock = $this->container->get(ClockInterface::class);
        self::assertInstanceOf(CanonicalEncoder::class, $encoder);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $tables = new TableNames($session, $this->settings()->tablePrefix);
        $transactions = new DoctrineTransactionManager($session);
        $recorder = new DoctrineAuditRecorder($session, $tables, $encoder);
        $archives = new FilesystemAuditArchiveVerifier($this->scratch . '/archives');

        return new DoctrineAuditRetentionService(
            $session,
            $tables,
            $transactions,
            new DoctrineAuditTrailExporter(
                $session,
                $tables,
                $transactions,
                new FilesystemAuditArchiveStorage($this->scratch . '/archives'),
                $recorder,
                $clock,
                new AllowingAuditAuthorization(),
            ),
            $recorder,
            $clock,
            new AllowingAuditAuthorization(),
            $encoder,
            $archives,
            new DoctrineAuditTrailVerifier($session, $tables, new AllowingAuditAuthorization(), $encoder, $archives),
            $runtime,
        );
    }

    /**
     * Compose a verifier on the runtime connection with the given private checkpoint store.
     *
     * @param   ?FilesystemAuditCheckpointStore  $checkpoints  Private checkpoint store, or null for none.
     *
     * @return  DoctrineAuditTrailVerifier  Verifier over the integration database.
     *
     * @since   2.0.0
     */
    private function verifier(?FilesystemAuditCheckpointStore $checkpoints): DoctrineAuditTrailVerifier
    {
        $encoder = $this->container->get(CanonicalEncoder::class);
        self::assertInstanceOf(CanonicalEncoder::class, $encoder);

        return new DoctrineAuditTrailVerifier(
            $this->database,
            $this->tables,
            new AllowingAuditAuthorization(),
            $encoder,
            new FilesystemAuditArchiveVerifier($this->scratch . '/archives'),
            $checkpoints,
        );
    }

    /**
     * Build a console authorizer resolving every token to an audit manager.
     *
     * @return  ConsoleAuthorizer  Authorizer for the verification command.
     *
     * @since   2.0.0
     */
    private function consoleAuthorizer(): ConsoleAuthorizer
    {
        return new ConsoleAuthorizer(new class implements AccessTokenVerifier {
            public function verify(
                string $token,
                string $audience = 'kumwe-http',
                string $purpose = 'api',
                ?string $site = null,
            ): ?AuthenticatedPrincipal {
                return AuthorizationContext::principal(['audit.manage']);
            }
        });
    }

    /**
     * Record audit events through the kernel's recorder.
     *
     * @param   int     $count  Events to record.
     * @param   string  $shift  Occurrence offset from now.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(int $count, string $shift): void
    {
        $recorder = $this->container->get(\Kumwe\Audit\Application\AuditRecorder::class);
        self::assertInstanceOf(\Kumwe\Audit\Application\AuditRecorder::class, $recorder);
        $now = (new DateTimeImmutable('now'))->modify($shift);
        for ($index = 0; $index < $count; $index++) {
            $recorder->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now->modify(sprintf('+%d seconds', $index)),
                'authority-actor',
                'identity.user.activated',
                'identity.user',
                'authority-subject-' . $index,
                'success',
                ['request_id' => 'authority-' . $index],
            ));
        }
    }

    /**
     * Resolve the kernel's anchor writer.
     *
     * @return  AuditAnchorWriter  Anchor writer over the runtime connection.
     *
     * @since   2.0.0
     */
    private function anchors(): AuditAnchorWriter
    {
        $anchors = $this->container->get(AuditAnchorWriter::class);
        self::assertInstanceOf(AuditAnchorWriter::class, $anchors);

        return $anchors;
    }

    /**
     * Mint the installation-maintenance context the audit jobs run under.
     *
     * @param   ?Container  $container  Kernel whose gateway will authorize the context; the test's own by default.
     *
     * @return  ExecutionContext  System context allowed to manage the audit trail.
     *
     * @since   2.0.0
     */
    private function context(?Container $container = null): ExecutionContext
    {
        $container ??= $this->container;
        $principals = $container->get(GlobalJobPrincipals::class);
        $scope = $container->get(JobExecutionScope::class);
        self::assertInstanceOf(GlobalJobPrincipals::class, $principals);
        self::assertInstanceOf(JobExecutionScope::class, $scope);

        return $principals->context(
            AuditTamperEvidenceMigration::RETENTION_JOB_TYPE,
            $scope,
            'audit-authority-' . bin2hex(random_bytes(8)),
            'audit-authority',
        );
    }

    /**
     * Count the rows of one audit table on the runtime connection.
     *
     * @param   string  $table  Logical table name.
     *
     * @return  int  Row count.
     *
     * @since   2.0.0
     */
    private function rows(string $table): int
    {
        return (int) $this->database->fetchOne('SELECT COUNT(*) FROM ' . $this->tables->quoted($table));
    }

    /**
     * Count retention marks in the ledger.
     *
     * @return  int  Prune mark count.
     *
     * @since   2.0.0
     */
    private function prunes(): int
    {
        return (int) $this->database->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->tables->quoted('audit_anchors') . " WHERE kind = 'prune'",
        );
    }

    /**
     * Name the PostgreSQL schema the audit tables live in.
     *
     * @return  string  Quoted current schema.
     *
     * @since   2.0.0
     */
    private function schema(): string
    {
        return $this->database->quoteSingleIdentifier((string) $this->database->fetchOne('SELECT current_schema()'));
    }

    /**
     * Remove a scratch directory tree.
     *
     * @param   string  $path  Directory to remove.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function remove(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}
