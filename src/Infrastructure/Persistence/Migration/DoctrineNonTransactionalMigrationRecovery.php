<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Journals implicit-DDL migrations before they start and resumes only proven strategies.
 *
 * An interrupted immutable Core migration is replayed from its clean prefix-scoped baseline.
 * Application authorization is reconciled by a dedicated postcondition verifier because its
 * published migration is not repeatable. Current idempotent migrations and migrations explicitly
 * implementing RepeatableMigration are replayed in place. Every other interrupted migration fails
 * closed rather than guessing whether partially committed DDL is safe.
 */
final readonly class DoctrineNonTransactionalMigrationRecovery implements NonTransactionalMigrationRecovery
{
    private const TABLE = 'migration_attempts';
    private const STRATEGY_CORE = 'fresh_core_v1';
    private const STRATEGY_APPLICATION_AUTHORIZATION = 'application_authorization_v1';
    private const STRATEGY_REPEAT = 'repeatable_v1';
    private const STRATEGY_FAIL_CLOSED = 'fail_closed_v1';

    private const PUBLISHED_CORE_CHECKSUM = '69741c8e3fc14a1a0e318a643deb3fa7901685ba8f534a1782917839ad1f0b57';
    private const PUBLISHED_APPLICATION_AUTHORIZATION_CHECKSUM =
        '873179e96a0e4ce35ec40adc7c62ea90c707fbbc7f4ede3baa1c56d849a5e785';

    /** @var list<string> */
    private const CURRENT_REPEATABLE_MIGRATIONS = [
        JobRecoveryMigration::ID,
        AuthorizationRecoveryIntegrationMigration::ID,
        TokenAndTrustLifecycleMigration::ID,
    ];

    /** @var list<string> */
    private const CORE_TABLES = [
        'administrator_sessions',
        'api_tokens',
        'audit_events',
        'capabilities',
        'content_entries',
        'content_revisions',
        'content_types',
        'extension_dependencies',
        'extension_migrations',
        'extension_releases',
        'extension_runtime_generation',
        'extension_trust_keys',
        'extensions',
        'failed_jobs',
        'idempotency',
        'jobs',
        'navigation_items',
        'navigation_menus',
        'password_credentials',
        'role_capability_grants',
        'roles',
        'schedules',
        'site_settings',
        'user_roles',
        'users',
        'worker_heartbeats',
        'workflow_states',
        'workflow_transitions',
        'workflows',
    ];

    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ApplicationAuthorizationMigrationRecovery $applicationAuthorization,
    ) {
    }

    public function assertKnownAttempts(array $knownMigrationIds): void
    {
        if (!$this->journalExists()) {
            return;
        }
        $this->assertJournalSchema();
        $attempts = $this->database->fetchFirstColumn(sprintf(
            'SELECT version FROM %s ORDER BY version',
            $this->tables->quoted(self::TABLE),
        ));
        foreach ($attempts as $attempt) {
            if (!is_string($attempt) || !in_array($attempt, $knownMigrationIds, true)) {
                throw new RuntimeException(sprintf(
                    'Migration recovery contains the unknown attempt "%s".',
                    is_scalar($attempt) ? (string) $attempt : 'invalid',
                ));
            }
        }
    }

    public function hasUnresolvedAttempts(): bool
    {
        if (!$this->journalExists()) {
            return false;
        }
        $this->assertJournalSchema();

        return $this->nonNegativeInteger($this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted(self::TABLE),
        )), 'migration recovery attempt count') > 0;
    }

    public function prepare(Migration $migration): NonTransactionalMigrationAction
    {
        $this->ensureJournal();
        $this->assertOnlyAttempt($migration->id());
        $strategy = $this->strategy($migration);
        $attempt = $this->attempt($migration->id());

        if ($attempt === null) {
            $state = $strategy === self::STRATEGY_APPLICATION_AUTHORIZATION
                ? $this->applicationAuthorization->capture()
                : ['strategy' => $strategy];
            $now = $this->now();
            $this->database->insert($this->tables->raw(self::TABLE), [
                'version' => $migration->id(),
                'checksum' => $migration->checksum(),
                'baseline_tables' => $this->encode(
                    $strategy === self::STRATEGY_CORE ? $this->prefixedTables() : [],
                ),
                'recovery_state' => $this->encode($state),
                'started_at' => $now,
                'updated_at' => $now,
            ], [
                'version' => Types::STRING,
                'checksum' => Types::STRING,
                'baseline_tables' => Types::TEXT,
                'recovery_state' => Types::TEXT,
                'started_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);

            return NonTransactionalMigrationAction::Execute;
        }

        $this->assertChecksum($migration, $attempt);
        $state = $this->state($attempt);
        if (($state['strategy'] ?? null) !== $strategy) {
            throw new RuntimeException(sprintf(
                'Interrupted migration recovery strategy drift detected for "%s".',
                $migration->id(),
            ));
        }

        if ($strategy === self::STRATEGY_CORE) {
            $this->recoverFreshCore($this->baseline($attempt));
        } elseif ($strategy === self::STRATEGY_APPLICATION_AUTHORIZATION) {
            $this->applicationAuthorization->recover($state);
            $this->touch($migration->id());

            return NonTransactionalMigrationAction::RecordRecovered;
        } elseif ($strategy === self::STRATEGY_FAIL_CLOSED) {
            throw new RuntimeException(sprintf(
                'Interrupted migration "%s" is not declared repeatable and requires operator recovery.',
                $migration->id(),
            ));
        }

        $this->touch($migration->id());

        return NonTransactionalMigrationAction::Execute;
    }

    public function complete(Migration $migration): void
    {
        $attempt = $this->attempt($migration->id());
        if ($attempt === null) {
            throw new RuntimeException(sprintf(
                'The non-transactional migration attempt for "%s" is missing.',
                $migration->id(),
            ));
        }
        $this->assertChecksum($migration, $attempt);
        $deleted = $this->database->delete($this->tables->raw(self::TABLE), [
            'version' => $migration->id(),
        ]);
        if ($deleted !== 1) {
            throw new RuntimeException(sprintf(
                'The completed migration attempt for "%s" could not be retired.',
                $migration->id(),
            ));
        }
    }

    public function reconcileApplied(Migration $migration): void
    {
        $this->ensureJournal();
        $attempt = $this->attempt($migration->id());
        if ($attempt === null) {
            return;
        }
        $this->assertChecksum($migration, $attempt);
        $deleted = $this->database->delete($this->tables->raw(self::TABLE), [
            'version' => $migration->id(),
        ]);
        if ($deleted !== 1) {
            throw new RuntimeException(sprintf(
                'The applied migration attempt for "%s" could not be reconciled.',
                $migration->id(),
            ));
        }
    }

    private function strategy(Migration $migration): string
    {
        if ($migration->id() === CoreSchemaMigration::ID) {
            if (!hash_equals(self::PUBLISHED_CORE_CHECKSUM, $migration->checksum())) {
                throw new RuntimeException('The immutable Core migration checksum is not recognized.');
            }

            return self::STRATEGY_CORE;
        }
        if ($migration->id() === ApplicationAuthorizationMigration::ID) {
            if (!hash_equals(self::PUBLISHED_APPLICATION_AUTHORIZATION_CHECKSUM, $migration->checksum())) {
                throw new RuntimeException('The immutable application-authorization checksum is not recognized.');
            }

            return self::STRATEGY_APPLICATION_AUTHORIZATION;
        }
        if (
            $migration instanceof RepeatableMigration
            || in_array($migration->id(), self::CURRENT_REPEATABLE_MIGRATIONS, true)
        ) {
            return self::STRATEGY_REPEAT;
        }

        return self::STRATEGY_FAIL_CLOSED;
    }

    private function ensureJournal(): void
    {
        $schema = $this->database->createSchemaManager();
        $name = $this->tables->raw(self::TABLE);
        if (!$schema->tablesExist([$name])) {
            $table = new Table($name);
            $table->addColumn('version', Types::STRING, ['length' => 191]);
            $table->addColumn('checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
            $table->addColumn('baseline_tables', Types::JSON);
            $table->addColumn('recovery_state', Types::JSON);
            $table->addColumn('started_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('version')->create(),
            );
            $schema->createTable($table);

            return;
        }

        $this->assertJournalSchema();
    }

    private function journalExists(): bool
    {
        return $this->database->createSchemaManager()->tablesExist([$this->tables->raw(self::TABLE)]);
    }

    private function assertJournalSchema(): void
    {
        $schema = $this->database->createSchemaManager();
        $name = $this->tables->raw(self::TABLE);

        $table = $schema->introspectTableByUnquotedName($name);
        $expected = ['baseline_tables', 'checksum', 'recovery_state', 'started_at', 'updated_at', 'version'];
        $actual = array_map(
            static fn (Column $column): string => $column->getObjectName()->toString(),
            $table->getColumns(),
        );
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('The non-transactional migration journal schema is divergent.');
        }
        if (
            !$table->getColumn('version')->getType() instanceof StringType
            || $table->getColumn('version')->getLength() !== 191
            || !$table->getColumn('checksum')->getType() instanceof StringType
            || $table->getColumn('checksum')->getLength() !== 64
            || !$table->getColumn('checksum')->getFixed()
            || !$table->getColumn('baseline_tables')->getType() instanceof JsonType
            || !$table->getColumn('recovery_state')->getType() instanceof JsonType
        ) {
            throw new RuntimeException('The non-transactional migration journal columns are divergent.');
        }
        $primary = $table->getPrimaryKeyConstraint();
        if (
            $primary !== null
            && array_map(
                static fn (\Doctrine\DBAL\Schema\Name $name): string => $name->toString(),
                $primary->getColumnNames(),
            ) === ['version']
        ) {
            return;
        }
        throw new RuntimeException('The non-transactional migration journal primary key is divergent.');
    }

    private function assertOnlyAttempt(string $id): void
    {
        $other = $this->database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version <> ? ORDER BY version',
            $this->tables->quoted(self::TABLE),
        ), [$id]);
        if ($other !== false) {
            throw new RuntimeException(sprintf(
                'Migration recovery is blocked by the unresolved attempt "%s".',
                is_scalar($other) ? (string) $other : 'invalid',
            ));
        }
    }

    /** @return array<string, mixed>|null */
    private function attempt(string $id): ?array
    {
        $attempt = $this->database->fetchAssociative(sprintf(
            'SELECT version, checksum, baseline_tables, recovery_state FROM %s WHERE version = ?',
            $this->tables->quoted(self::TABLE),
        ), [$id]);

        return $attempt === false ? null : $attempt;
    }

    /** @param array<string, mixed> $attempt */
    private function assertChecksum(Migration $migration, array $attempt): void
    {
        $checksum = $attempt['checksum'] ?? null;
        if (!is_string($checksum) || !hash_equals($migration->checksum(), $checksum)) {
            throw new RuntimeException(sprintf(
                'Interrupted migration checksum drift detected for "%s".',
                $migration->id(),
            ));
        }
    }

    /**
     * @param array<string, mixed> $attempt
     * @return list<string>
     */
    private function baseline(array $attempt): array
    {
        $baseline = $this->decode($attempt['baseline_tables'] ?? null, 'Core migration baseline');
        if (!array_is_list($baseline)) {
            throw new RuntimeException('The interrupted Core migration baseline is invalid.');
        }
        foreach ($baseline as $table) {
            if (!is_string($table) || !$this->hasPrefix($table)) {
                throw new RuntimeException('The interrupted Core migration baseline escapes its table prefix.');
            }
        }

        /** @var list<string> $baseline */
        return $baseline;
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function state(array $attempt): array
    {
        $state = $this->decode($attempt['recovery_state'] ?? null, 'migration recovery state');
        if (array_is_list($state)) {
            throw new RuntimeException('The interrupted migration recovery state is invalid.');
        }

        foreach (array_keys($state) as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('The interrupted migration recovery state is invalid.');
            }
        }

        /** @var array<string, mixed> $state */
        return $state;
    }

    /** @param list<string> $baseline */
    private function recoverFreshCore(array $baseline): void
    {
        $created = array_values(array_diff($this->prefixedTables(), $baseline));
        if ($created === []) {
            return;
        }

        $allowed = array_map(
            fn (string $table): string => $this->tables->raw($table),
            self::CORE_TABLES,
        );
        foreach ($created as $tableName) {
            if (!in_array($tableName, $allowed, true)) {
                throw new RuntimeException(sprintf(
                    'Interrupted Core recovery found the unexpected table "%s"; no data was removed.',
                    $tableName,
                ));
            }
        }

        $schema = $this->database->createSchemaManager();
        foreach ($created as $tableName) {
            $table = $schema->introspectTableByUnquotedName($tableName);
            foreach ($table->getForeignKeys() as $foreignKey) {
                $schema->dropForeignKey($this->constraintName($foreignKey), $tableName);
            }
        }
        foreach (array_reverse($created) as $tableName) {
            if ($schema->tablesExist([$tableName])) {
                $schema->dropTable($tableName);
            }
        }
    }

    /** @return list<string> */
    private function prefixedTables(): array
    {
        $tables = array_values(array_filter(array_map(
            static fn (\Doctrine\DBAL\Schema\Name\OptionallyQualifiedName $table): string => $table->toString(),
            $this->database->createSchemaManager()->introspectTableNames(),
        ), fn (string $table): bool => $this->hasPrefix($table)));
        sort($tables, SORT_STRING);

        return $tables;
    }

    private function hasPrefix(string $table): bool
    {
        $ledger = $this->tables->raw('schema_migrations');
        $prefix = substr($ledger, 0, -strlen('schema_migrations'));

        return $prefix !== '' && str_starts_with($table, $prefix);
    }

    private function touch(string $id): void
    {
        $updated = $this->database->update(
            $this->tables->raw(self::TABLE),
            ['updated_at' => $this->now()],
            ['version' => $id],
            ['updated_at' => Types::DATETIME_IMMUTABLE],
        );
        if ($updated !== 1 && $this->attempt($id) === null) {
            throw new RuntimeException(sprintf('The migration attempt "%s" lost its journal row.', $id));
        }
    }

    private function constraintName(ForeignKeyConstraint $constraint): string
    {
        $name = $constraint->getObjectName();
        if ($name === null) {
            throw new RuntimeException('Interrupted Core recovery found an unnamed foreign key.');
        }

        return $name->toString();
    }

    /** @param array<mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Migration recovery state could not be encoded.', 0, $exception);
        }
    }

    /** @return array<mixed> */
    private function decode(mixed $encoded, string $label): array
    {
        if (!is_string($encoded)) {
            throw new RuntimeException(sprintf('The interrupted %s is invalid.', $label));
        }
        try {
            $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('The interrupted %s is invalid.', $label), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('The interrupted %s is invalid.', $label));
        }

        return $decoded;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('The %s is invalid.', $label));
        }

        return (int) $value;
    }
}
