<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Completes the immutable application-authorization migration after implicit-DDL interruption.
 *
 * The original migration remains byte-for-byte unchanged. This recovery path reconstructs its
 * deterministic postcondition from state captured before the first attempt, then verifies every
 * security-sensitive schema and ownership invariant before the runner records the original hash.
 */
final readonly class ApplicationAuthorizationMigrationRecovery
{
    private const STRATEGY = 'application_authorization_v1';

    /** @var array<string, array{table: string, column: string}> */
    private const OWNED_RESOURCES = [
        'administrator_session' => ['table' => 'administrator_sessions', 'column' => 'id'],
        'api_token' => ['table' => 'api_tokens', 'column' => 'id'],
        'capability' => ['table' => 'capabilities', 'column' => 'code'],
        'content' => ['table' => 'content_entries', 'column' => 'id'],
        'extension' => ['table' => 'extensions', 'column' => 'identifier'],
        'grant' => ['table' => 'role_capability_grants', 'column' => 'id'],
        'job' => ['table' => 'jobs', 'column' => 'id'],
        'menu' => ['table' => 'navigation_menus', 'column' => 'id'],
        'menu_item' => ['table' => 'navigation_items', 'column' => 'id'],
        'role' => ['table' => 'roles', 'column' => 'id'],
        'schedule' => ['table' => 'schedules', 'column' => 'id'],
        'user' => ['table' => 'users', 'column' => 'id'],
    ];

    /** @var array<string, string> */
    private const CAPABILITIES = [
        'themes.administrator.manage' => 'Manage administrator presentation themes.',
        'themes.site.manage' => 'Manage public site presentation themes.',
    ];

    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * @return array{
     *     strategy: string,
     *     administrator_roles: list<string>,
     *     administrator_security_epochs: list<array{user_id: string, epoch: int}>
     * }
     */
    public function capture(): array
    {
        $manager = $this->database->createSchemaManager();
        $required = [
            'users',
            'roles',
            'user_roles',
            'capabilities',
            'role_capability_grants',
            'idempotency',
        ];
        foreach ($required as $table) {
            if (!$manager->tablesExist([$this->tables->raw($table)])) {
                throw new RuntimeException(sprintf(
                    'Application-authorization recovery cannot capture a parent schema without "%s".',
                    $table,
                ));
            }
        }

        $users = $manager->introspectTableByUnquotedName($this->tables->raw('users'));
        $idempotency = $manager->introspectTableByUnquotedName($this->tables->raw('idempotency'));
        if (
            $users->hasColumn('security_epoch')
            || $manager->tablesExist([$this->tables->raw('sites')])
            || $manager->tablesExist([$this->tables->raw('resource_site_ownership')])
            || $idempotency->hasColumn('authorization_fingerprint')
            || $idempotency->hasColumn('lease_owner')
            || $idempotency->hasColumn('lease_expires_at')
        ) {
            throw new RuntimeException(
                'The application-authorization parent schema is already partial; recovery has no durable baseline.',
            );
        }

        foreach (array_keys(self::CAPABILITIES) as $capability) {
            if (
                $this->database->fetchOne(sprintf(
                    'SELECT code FROM %s WHERE code = ?',
                    $this->tables->quoted('capabilities'),
                ), [$capability]) !== false
            ) {
                throw new RuntimeException(
                    'The application-authorization parent data already contains migration-owned capabilities.',
                );
            }
        }

        $roles = $this->administratorRoleIds();
        $epochs = [];
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT ur.user_id, COUNT(*) AS role_count FROM %s ur INNER JOIN %s r ON r.id = ur.role_id '
            . 'WHERE r.code = ? GROUP BY ur.user_id ORDER BY ur.user_id',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('roles'),
        ), ['administrator']);
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            $count = $this->nonNegativeInteger($row['role_count'] ?? null, 'administrator role count');
            if (!is_string($userId) || $userId === '' || $count < 1) {
                throw new RuntimeException('The administrator security-epoch baseline is invalid.');
            }
            $epochs[] = ['user_id' => $userId, 'epoch' => 1 + $count];
        }

        return [
            'strategy' => self::STRATEGY,
            'administrator_roles' => $roles,
            'administrator_security_epochs' => $epochs,
        ];
    }

    /** @param array<string, mixed> $state */
    public function recover(array $state): void
    {
        [$roles, $epochs] = $this->state($state);
        if ($roles !== $this->administratorRoleIds()) {
            throw new RuntimeException('Administrator roles changed during application-authorization recovery.');
        }

        $this->assertAdministratorUsers($epochs);
        $this->database->executeStatement(sprintf('DELETE FROM %s', $this->tables->quoted('idempotency')));
        $this->ensureSchema();
        $this->reconcileSite();
        $this->reconcileCapabilitiesAndGrants($roles);
        $this->reconcileSecurityEpochs($epochs);
        $this->reconcileOwnership();
        $this->verifySchema();
        $this->verifyOwnership();
    }

    /**
     * @param array<string, mixed> $state
     * @return array{0: list<string>, 1: array<string, int>}
     */
    private function state(array $state): array
    {
        if (
            ($state['strategy'] ?? null) !== self::STRATEGY
            || !is_array($state['administrator_roles'] ?? null)
            || !array_is_list($state['administrator_roles'])
            || !is_array($state['administrator_security_epochs'] ?? null)
            || !array_is_list($state['administrator_security_epochs'])
        ) {
            throw new RuntimeException('The application-authorization recovery state is invalid.');
        }

        $roles = [];
        foreach ($state['administrator_roles'] as $role) {
            if (!is_string($role) || $role === '') {
                throw new RuntimeException('The application-authorization recovery role is invalid.');
            }
            $roles[] = $role;
        }
        $sortedRoles = $roles;
        sort($sortedRoles, SORT_STRING);
        if ($roles !== $sortedRoles || count($roles) !== count(array_unique($roles))) {
            throw new RuntimeException('The application-authorization recovery roles are not canonical.');
        }

        $epochs = [];
        foreach ($state['administrator_security_epochs'] as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException('The application-authorization recovery epoch is invalid.');
            }
            $userId = $entry['user_id'] ?? null;
            $epoch = $entry['epoch'] ?? null;
            $keys = array_keys($entry);
            sort($keys, SORT_STRING);
            if (
                !is_string($userId) || $userId === '' || !is_int($epoch) || $epoch < 2
                || $keys !== ['epoch', 'user_id'] || isset($epochs[$userId])
            ) {
                throw new RuntimeException('The application-authorization recovery epoch is invalid.');
            }
            $epochs[$userId] = $epoch;
        }
        $ordered = array_keys($epochs);
        $sorted = $ordered;
        sort($sorted, SORT_STRING);
        if ($ordered !== $sorted) {
            throw new RuntimeException('The application-authorization recovery epochs are not canonical.');
        }

        return [$roles, $epochs];
    }

    /** @return list<string> */
    private function administratorRoleIds(): array
    {
        $values = $this->database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE code = ? ORDER BY id',
            $this->tables->quoted('roles'),
        ), ['administrator']);
        $roles = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('An administrator role identifier is invalid.');
            }
            $roles[] = $value;
        }

        return $roles;
    }

    /** @param array<string, int> $epochs */
    private function assertAdministratorUsers(array $epochs): void
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT ur.user_id, COUNT(*) AS role_count FROM %s ur INNER JOIN %s r ON r.id = ur.role_id '
            . 'WHERE r.code = ? GROUP BY ur.user_id ORDER BY ur.user_id',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('roles'),
        ), ['administrator']);
        $current = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            $count = $this->nonNegativeInteger($row['role_count'] ?? null, 'administrator role count');
            if (!is_string($userId) || $userId === '' || $count < 1) {
                throw new RuntimeException('The current administrator assignment is invalid.');
            }
            $current[$userId] = 1 + $count;
        }
        if ($current !== $epochs) {
            throw new RuntimeException('Administrator assignments changed during application-authorization recovery.');
        }
    }

    private function ensureSchema(): void
    {
        $manager = $this->database->createSchemaManager();
        $before = $manager->introspectSchema();
        $this->assertCompatiblePartialSchema($before);
        $after = clone $before;

        $users = $after->getTable($this->tables->raw('users'));
        if (!$users->hasColumn('security_epoch')) {
            $users->addColumn('security_epoch', Types::INTEGER, ['default' => 1]);
        }

        $sitesName = $this->tables->raw('sites');
        if (!$after->hasTable($sitesName)) {
            $sites = $after->createTable($sitesName);
            $sites->addColumn('identifier', Types::STRING, ['length' => 191]);
            $sites->addColumn('name', Types::STRING, ['length' => 191]);
            $sites->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $sites->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('identifier')->create(),
            );
        }

        $ownershipName = $this->tables->raw('resource_site_ownership');
        if (!$after->hasTable($ownershipName)) {
            $ownership = $after->createTable($ownershipName);
            $ownership->addColumn('resource_type', Types::STRING, ['length' => 63]);
            $ownership->addColumn('resource_id', Types::STRING, ['length' => 191]);
            $ownership->addColumn('site_identifier', Types::STRING, ['length' => 191]);
            $ownership->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setUnquotedColumnNames('resource_type', 'resource_id')
                    ->create(),
            );
        }
        $ownership = $after->getTable($ownershipName);
        if (!$ownership->hasIndex('idx_resource_site')) {
            $ownership->addIndex(['site_identifier', 'resource_type'], 'idx_resource_site');
        }
        if ($this->ownershipForeignKey($ownership) === null) {
            $ownership->addForeignKeyConstraint(
                $sitesName,
                ['site_identifier'],
                ['identifier'],
                ['onDelete' => 'CASCADE'],
                'fk_resource_site_' . substr(hash('sha256', $ownershipName), 0, 16),
            );
        }

        $idempotency = $after->getTable($this->tables->raw('idempotency'));
        if (!$idempotency->hasColumn('authorization_fingerprint')) {
            $idempotency->addColumn(
                'authorization_fingerprint',
                Types::STRING,
                ['length' => 64, 'fixed' => true],
            );
        }
        if (!$idempotency->hasColumn('lease_owner')) {
            $idempotency->addColumn('lease_owner', Types::STRING, ['length' => 64]);
        }
        if (!$idempotency->hasColumn('lease_expires_at')) {
            $idempotency->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE);
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($this->database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $this->database->executeStatement($statement);
        }
    }

    private function assertCompatiblePartialSchema(Schema $schema): void
    {
        $users = $schema->getTable($this->tables->raw('users'));
        if ($users->hasColumn('security_epoch')) {
            $this->assertIntegerColumn($users, 'security_epoch', true, '1');
        }
        $idempotency = $schema->getTable($this->tables->raw('idempotency'));
        if ($idempotency->hasColumn('authorization_fingerprint')) {
            $this->assertStringColumn($idempotency, 'authorization_fingerprint', 64, true, true);
        }
        if ($idempotency->hasColumn('lease_owner')) {
            $this->assertStringColumn($idempotency, 'lease_owner', 64, true, false);
        }
        if ($idempotency->hasColumn('lease_expires_at')) {
            $this->assertDateTimeColumn($idempotency, 'lease_expires_at', true);
        }

        $sitesName = $this->tables->raw('sites');
        if ($schema->hasTable($sitesName)) {
            $sites = $schema->getTable($sitesName);
            $this->assertExactColumns($sites, ['created_at', 'identifier', 'name']);
            $this->assertStringColumn($sites, 'identifier', 191, true, false);
            $this->assertStringColumn($sites, 'name', 191, true, false);
            $this->assertDateTimeColumn($sites, 'created_at', true);
            $this->assertPrimaryKey($sites, ['identifier']);
        }

        $ownershipName = $this->tables->raw('resource_site_ownership');
        if ($schema->hasTable($ownershipName)) {
            $ownership = $schema->getTable($ownershipName);
            $this->assertExactColumns($ownership, ['resource_id', 'resource_type', 'site_identifier']);
            $this->assertStringColumn($ownership, 'resource_type', 63, true, false);
            $this->assertStringColumn($ownership, 'resource_id', 191, true, false);
            $this->assertStringColumn($ownership, 'site_identifier', 191, true, false);
            $this->assertPrimaryKey($ownership, ['resource_type', 'resource_id']);
            if ($ownership->hasIndex('idx_resource_site')) {
                $this->assertIndex($ownership, 'idx_resource_site', ['site_identifier', 'resource_type']);
            }
            if ($this->ownershipForeignKey($ownership) !== null) {
                $this->assertOwnershipForeignKey($ownership);
            }
        }
    }

    private function verifySchema(): void
    {
        $schema = $this->database->createSchemaManager()->introspectSchema();
        $this->assertCompatiblePartialSchema($schema);
        $users = $schema->getTable($this->tables->raw('users'));
        $idempotency = $schema->getTable($this->tables->raw('idempotency'));
        if (
            !$users->hasColumn('security_epoch')
            || !$schema->hasTable($this->tables->raw('sites'))
            || !$schema->hasTable($this->tables->raw('resource_site_ownership'))
            || !$idempotency->hasColumn('authorization_fingerprint')
            || !$idempotency->hasColumn('lease_owner')
            || !$idempotency->hasColumn('lease_expires_at')
        ) {
            throw new RuntimeException('Application-authorization recovery did not reach its schema postcondition.');
        }
        $ownership = $schema->getTable($this->tables->raw('resource_site_ownership'));
        $this->assertIndex($ownership, 'idx_resource_site', ['site_identifier', 'resource_type']);
        $this->assertOwnershipForeignKey($ownership);
    }

    private function reconcileSite(): void
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT identifier, name FROM %s ORDER BY identifier',
            $this->tables->quoted('sites'),
        ));
        if ($rows === []) {
            $this->database->insert($this->tables->raw('sites'), [
                'identifier' => SiteContext::DEFAULT,
                'name' => 'Default site',
                'created_at' => $this->now(),
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);

            return;
        }
        if (
            count($rows) !== 1
            || ($rows[0]['identifier'] ?? null) !== SiteContext::DEFAULT
            || ($rows[0]['name'] ?? null) !== 'Default site'
        ) {
            throw new RuntimeException('The interrupted site seed is divergent.');
        }
    }

    /** @param list<string> $roles */
    private function reconcileCapabilitiesAndGrants(array $roles): void
    {
        foreach (self::CAPABILITIES as $code => $description) {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT code, description FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$code]);
            if ($row === false) {
                $this->database->insert($this->tables->raw('capabilities'), [
                    'code' => $code,
                    'description' => $description,
                ]);
            } elseif (($row['description'] ?? null) !== $description) {
                throw new RuntimeException(sprintf('The interrupted capability "%s" is divergent.', $code));
            }

            foreach ($roles as $roleId) {
                $grants = $this->database->fetchAllAssociative(sprintf(
                    'SELECT id, scope_type, scope_identifier FROM %s '
                    . 'WHERE role_id = ? AND capability_code = ? ORDER BY id',
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $code]);
                if ($grants === []) {
                    $this->database->insert($this->tables->raw('role_capability_grants'), [
                        'id' => Uuid::uuid7()->toString(),
                        'role_id' => $roleId,
                        'capability_code' => $code,
                        'scope_type' => 'global',
                        'scope_identifier' => null,
                        'granted_at' => $this->now(),
                        'granted_by' => null,
                    ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
                } elseif (
                    count($grants) !== 1
                    || ($grants[0]['scope_type'] ?? null) !== 'global'
                    || ($grants[0]['scope_identifier'] ?? null) !== null
                ) {
                    throw new RuntimeException(sprintf('The interrupted grant for "%s" is divergent.', $code));
                }
            }

            $grantedRoles = $this->database->fetchFirstColumn(sprintf(
                'SELECT role_id FROM %s WHERE capability_code = ? ORDER BY role_id',
                $this->tables->quoted('role_capability_grants'),
            ), [$code]);
            if ($grantedRoles !== $roles) {
                throw new RuntimeException(sprintf('The interrupted grants for "%s" are divergent.', $code));
            }
        }
    }

    /** @param array<string, int> $epochs */
    private function reconcileSecurityEpochs(array $epochs): void
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, security_epoch FROM %s ORDER BY id',
            $this->tables->quoted('users'),
        ));
        foreach ($rows as $row) {
            $userId = $row['id'] ?? null;
            if (!is_string($userId) || $userId === '') {
                throw new RuntimeException('An interrupted user identifier is invalid.');
            }
            $current = $this->nonNegativeInteger($row['security_epoch'] ?? null, 'security epoch');
            $expected = $epochs[$userId] ?? 1;
            if ($current < 1 || $current > $expected) {
                throw new RuntimeException(sprintf('The security epoch for user "%s" is divergent.', $userId));
            }
            if ($current !== $expected) {
                $this->database->update(
                    $this->tables->raw('users'),
                    ['security_epoch' => $expected],
                    ['id' => $userId],
                    ['security_epoch' => Types::INTEGER],
                );
            }
        }
    }

    private function reconcileOwnership(): void
    {
        foreach ($this->resourceIdentifiers() as $resourceType => $identifiers) {
            foreach ($identifiers as $identifier) {
                $site = $this->database->fetchOne(sprintf(
                    'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
                    $this->tables->quoted('resource_site_ownership'),
                ), [$resourceType, $identifier]);
                if ($site === false) {
                    $this->database->insert($this->tables->raw('resource_site_ownership'), [
                        'resource_type' => $resourceType,
                        'resource_id' => $identifier,
                        'site_identifier' => SiteContext::DEFAULT,
                    ]);
                } elseif ($site !== SiteContext::DEFAULT) {
                    throw new RuntimeException(sprintf('The %s ownership seed is divergent.', $resourceType));
                }
            }
        }
    }

    private function verifyOwnership(): void
    {
        $expected = $this->resourceIdentifiers();
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT resource_type, resource_id, site_identifier FROM %s ORDER BY resource_type, resource_id',
            $this->tables->quoted('resource_site_ownership'),
        ));
        $actual = [];
        foreach ($rows as $row) {
            $type = $row['resource_type'] ?? null;
            $id = $row['resource_id'] ?? null;
            if (
                !is_string($type) || !is_string($id) || !array_key_exists($type, $expected)
                || ($row['site_identifier'] ?? null) !== SiteContext::DEFAULT
            ) {
                throw new RuntimeException('The application-authorization ownership postcondition is divergent.');
            }
            $actual[$type][] = $id;
        }
        foreach ($expected as $type => $identifiers) {
            if (($actual[$type] ?? []) !== $identifiers) {
                throw new RuntimeException(sprintf('The %s ownership postcondition is incomplete.', $type));
            }
        }
    }

    /** @return array<string, list<string>> */
    private function resourceIdentifiers(): array
    {
        $resources = [];
        foreach (self::OWNED_RESOURCES as $resourceType => $mapping) {
            $column = $this->database->quoteSingleIdentifier($mapping['column']);
            $values = $this->database->fetchFirstColumn(sprintf(
                'SELECT %s FROM %s ORDER BY %s',
                $column,
                $this->tables->quoted($mapping['table']),
                $column,
            ));
            $identifiers = [];
            foreach ($values as $value) {
                if (!is_string($value) || $value === '') {
                    throw new RuntimeException(sprintf('A %s ownership identifier is invalid.', $resourceType));
                }
                $identifiers[] = $value;
            }
            $resources[$resourceType] = $identifiers;
        }

        return $resources;
    }

    /** @param list<string> $expected */
    private function assertExactColumns(Table $table, array $expected): void
    {
        $columns = array_map(
            static fn (Column $column): string => $column->getObjectName()->toString(),
            $table->getColumns(),
        );
        sort($columns, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($columns !== $expected) {
            throw new RuntimeException(sprintf(
                'The interrupted table "%s" has divergent columns.',
                $table->getObjectName()->toString(),
            ));
        }
    }

    private function assertStringColumn(
        Table $table,
        string $name,
        int $length,
        bool $notNull,
        bool $fixed,
    ): void {
        $column = $table->getColumn($name);
        if (
            !$column->getType() instanceof StringType
            || $column->getLength() !== $length
            || $column->getNotnull() !== $notNull
            || $column->getFixed() !== $fixed
        ) {
            throw new RuntimeException(sprintf(
                'The interrupted column "%s.%s" is divergent.',
                $table->getObjectName()->toString(),
                $name,
            ));
        }
    }

    private function assertIntegerColumn(Table $table, string $name, bool $notNull, string $default): void
    {
        $column = $table->getColumn($name);
        $actualDefault = $column->getDefault();
        if (
            !$column->getType() instanceof IntegerType
            || $column->getNotnull() !== $notNull
            || (!is_int($actualDefault) && !is_string($actualDefault))
            || (string) $actualDefault !== $default
        ) {
            throw new RuntimeException(sprintf(
                'The interrupted column "%s.%s" is divergent.',
                $table->getObjectName()->toString(),
                $name,
            ));
        }
    }

    private function assertDateTimeColumn(Table $table, string $name, bool $notNull): void
    {
        $column = $table->getColumn($name);
        if (
            (!$column->getType() instanceof DateTimeImmutableType && !$column->getType() instanceof DateTimeType)
            || $column->getNotnull() !== $notNull
        ) {
            throw new RuntimeException(sprintf(
                'The interrupted column "%s.%s" is divergent.',
                $table->getObjectName()->toString(),
                $name,
            ));
        }
    }

    /** @param list<string> $columns */
    private function assertPrimaryKey(Table $table, array $columns): void
    {
        $primary = $table->getPrimaryKeyConstraint();
        if ($primary === null || $this->names($primary->getColumnNames()) !== $columns) {
            throw new RuntimeException(sprintf(
                'The interrupted table "%s" has a divergent primary key.',
                $table->getObjectName()->toString(),
            ));
        }
    }

    /** @param list<string> $columns */
    private function assertIndex(Table $table, string $name, array $columns): void
    {
        foreach ($table->getIndexes() as $index) {
            if ($index->getObjectName()?->toString() !== $name) {
                continue;
            }
            $actual = array_map(
                static fn (\Doctrine\DBAL\Schema\Index\IndexedColumn $column): string =>
                    $column->getColumnName()->toString(),
                $index->getIndexedColumns(),
            );
            if ($actual !== $columns || $index->getType() !== IndexType::REGULAR) {
                throw new RuntimeException(sprintf('The interrupted index "%s" is divergent.', $name));
            }

            return;
        }
        throw new RuntimeException(sprintf('The interrupted index "%s" is missing.', $name));
    }

    private function assertOwnershipForeignKey(Table $table): void
    {
        $foreignKey = $this->ownershipForeignKey($table);
        if ($foreignKey === null) {
            throw new RuntimeException('The interrupted ownership foreign key is missing.');
        }
        if (
            $this->names($foreignKey->getReferencedColumnNames()) !== ['identifier']
            || $foreignKey->getOnDeleteAction() !== ReferentialAction::CASCADE
        ) {
            throw new RuntimeException('The interrupted ownership foreign key is divergent.');
        }
    }

    private function ownershipForeignKey(Table $table): ?ForeignKeyConstraint
    {
        foreach ($table->getForeignKeys() as $foreignKey) {
            if (
                $this->names($foreignKey->getReferencingColumnNames()) === ['site_identifier']
                && $foreignKey->getReferencedTableName()->toString() === $this->tables->raw('sites')
            ) {
                return $foreignKey;
            }
        }

        return null;
    }

    /**
     * @param non-empty-list<Name> $names
     * @return non-empty-list<string>
     */
    private function names(array $names): array
    {
        return array_map(static fn (Name $name): string => $name->toString(), $names);
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('The %s is invalid.', $label));
        }

        return (int) $value;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
