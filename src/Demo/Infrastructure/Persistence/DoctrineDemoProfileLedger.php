<?php

declare(strict_types=1);

namespace Kumwe\CMS\Demo\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Demo\Application\DemoProfileLedger;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

/**
 * Durable selector, reconciliation checkpoint, and fixture-ownership ledger for built-in demo profiles.
 *
 * The profile installer is repeatable application work rather than a schema migration. This store is its
 * restart boundary: the selected profile is frozen per dataset, each generated resource is mapped back to
 * a stable fixture key, and the canonical state last written by Kumwe is retained so a later release can
 * distinguish an untouched sample from an operator's customization. A session advisory lock surrounds a
 * full installation pass, including service calls that open their own transactions.
 *
 * @since  2.0.0
 */
final readonly class DoctrineDemoProfileLedger implements DemoProfileLedger
{
    /**
     * Bind the ledger to the configured database, table namespace, and trusted clock.
     *
     * @param  Connection      $database  Connection whose session owns the reconciliation lock.
     * @param  TableNames      $tables    Validated compiler for profile-ledger table names.
     * @param  ClockInterface  $clock     Trusted timestamp source for every checkpoint.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Run one complete profile reconciliation while this database session alone owns its site lock.
     *
     * @template T
     *
     * @param   string         $site       Site whose two profile datasets are being reconciled.
     * @param   callable(): T  $operation  Complete installation pass to execute under exclusion.
     *
     * @return  T  Value returned by the profile operation.
     *
     * @throws  RuntimeException  When the database is unsupported, another reconciler holds the lock,
     *          or the acquired lock cannot be released.
     * @throws  Throwable  When the profile operation fails; release is still attempted first.
     *
     * @since   2.0.0
     */
    public function synchronized(string $site, callable $operation): mixed
    {
        [$mysql, $lockName] = $this->advisoryIdentity($site);
        $acquired = $mysql
            ? $this->database->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName])
            : $this->database->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', [$lockName]);
        if (!$this->truthy($acquired)) {
            throw new RuntimeException('Another process is already reconciling the demo profiles.');
        }

        $failure = null;
        try {
            return $operation();
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try {
                $released = $mysql
                    ? $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName])
                    : $this->database->fetchOne(
                        'SELECT pg_advisory_unlock(hashtextextended(?, 0))',
                        [$lockName],
                    );
                if (!$this->truthy($released) && $failure === null) {
                    throw new RuntimeException('The demo-profile advisory lock could not be released.');
                }
            } catch (Throwable $releaseFailure) {
                if ($failure === null) {
                    throw $releaseFailure;
                }
            }
        }
    }

    /**
     * Derive one database-scoped advisory identity within MySQL's 64-character name limit.
     *
     * The database name, physical migration ledger, and full site identifier are hashed together. Two
     * installations sharing a server therefore do not block one another, while every replica aimed at
     * the same database, prefix, and site resolves exactly the same bounded lock name.
     *
     * @param   string  $site  Site whose profile datasets share the lock.
     *
     * @return  array{0: bool, 1: string}  MySQL-family discriminator and bounded lock name.
     *
     * @throws  RuntimeException  When the platform is unsupported or cannot name its current database.
     *
     * @since   2.0.0
     */
    private function advisoryIdentity(string $site): array
    {
        $platform = $this->database->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $databaseIdentity = $this->database->fetchOne('SELECT DATABASE()');
            $mysql = true;
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $databaseIdentity = $this->database->fetchOne('SELECT current_database()');
            $mysql = false;
        } else {
            throw new RuntimeException('The database has no supported demo-profile advisory lock.');
        }
        if (!is_string($databaseIdentity) || $databaseIdentity === '') {
            throw new RuntimeException('The database identity for the demo-profile lock is unavailable.');
        }

        return [$mysql, 'kumwe:demo:' . substr(hash(
            'sha256',
            $databaseIdentity . "\0" . $this->tables->raw('schema_migrations') . "\0" . $site,
        ), 0, 40)];
    }

    /**
     * Start or resume one immutable profile selection and decide whether its manifest needs work.
     *
     * @param   string  $site              Site that owns the selection.
     * @param   string  $dataset           Stable dataset key, such as `site-content`.
     * @param   string  $selectedProfile   Requested member of the dataset's closed profile vocabulary.
     * @param   int     $manifestVersion   Monotonic version declared by the selected manifest.
     * @param   string  $manifestChecksum  Canonical SHA-256 of the complete manifest.
     *
     * @return  bool  True when reconciliation must run; false when this exact manifest is complete.
     *
     * @throws  RuntimeException  When the stored selection differs, a release attempts a downgrade,
     *          manifest bytes change without a version increment, or the persisted checkpoint is malformed.
     *
     * @since   2.0.0
     */
    public function begin(
        string $site,
        string $dataset,
        string $selectedProfile,
        int $manifestVersion,
        string $manifestChecksum,
    ): bool {
        $now = $this->clock->now();
        $row = $this->installation($site, $dataset);
        if ($row === null) {
            $this->database->insert($this->tables->raw('demo_profile_installations'), [
                'site_identifier' => $site,
                'dataset_key' => $dataset,
                'selected_profile' => $selectedProfile,
                'manifest_version' => $manifestVersion,
                'manifest_checksum' => $manifestChecksum,
                'status' => 'applying',
                'created_at' => $now,
                'updated_at' => $now,
                'last_applied_at' => null,
            ], [
                'manifest_version' => Types::INTEGER,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
                'last_applied_at' => Types::DATETIME_IMMUTABLE,
            ]);

            return true;
        }
        $storedProfile = $row['selected_profile'] ?? null;
        if (!is_string($storedProfile) || $storedProfile === '') {
            throw new RuntimeException('The demo profile stored selection is invalid.');
        }
        if ($storedProfile !== $selectedProfile) {
            throw new RuntimeException(sprintf(
                'Demo dataset %s is locked to profile %s; refusing requested profile %s.',
                $dataset,
                $storedProfile,
                $selectedProfile,
            ));
        }
        $storedVersion = $this->integer($row['manifest_version'] ?? null, 'manifest version');
        if ($manifestVersion < $storedVersion) {
            throw new RuntimeException(sprintf('Demo dataset %s cannot be downgraded.', $dataset));
        }
        $storedChecksum = $row['manifest_checksum'] ?? null;
        if (!is_string($storedChecksum) || preg_match('/^[a-f0-9]{64}$/D', $storedChecksum) !== 1) {
            throw new RuntimeException('The demo profile manifest checksum is invalid.');
        }
        if ($storedVersion === $manifestVersion && $storedChecksum !== $manifestChecksum) {
            throw new RuntimeException(sprintf(
                'Demo dataset %s manifest version %d changed without a version increment.',
                $dataset,
                $manifestVersion,
            ));
        }
        if (
            $storedVersion === $manifestVersion
            && ($row['status'] ?? null) === 'complete'
        ) {
            return false;
        }

        $this->database->update($this->tables->raw('demo_profile_installations'), [
            'manifest_version' => $manifestVersion,
            'manifest_checksum' => $manifestChecksum,
            'status' => 'applying',
            'updated_at' => $now,
        ], [
            'site_identifier' => $site,
            'dataset_key' => $dataset,
        ], [
            'manifest_version' => Types::INTEGER,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);

        return true;
    }

    /**
     * Mark one manifest completely reconciled after every application service call succeeded.
     *
     * @param   string  $site     Site whose checkpoint completed.
     * @param   string  $dataset  Dataset whose checkpoint completed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(string $site, string $dataset): void
    {
        $now = $this->clock->now();
        $this->database->update($this->tables->raw('demo_profile_installations'), [
            'status' => 'complete',
            'updated_at' => $now,
            'last_applied_at' => $now,
        ], [
            'site_identifier' => $site,
            'dataset_key' => $dataset,
        ], [
            'updated_at' => Types::DATETIME_IMMUTABLE,
            'last_applied_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Mark an interrupted manifest without erasing the resource checkpoints already committed.
     *
     * @param   string  $site     Site whose reconciliation failed.
     * @param   string  $dataset  Dataset whose reconciliation failed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function failed(string $site, string $dataset): void
    {
        $this->database->update($this->tables->raw('demo_profile_installations'), [
            'status' => 'failed',
            'updated_at' => $this->clock->now(),
        ], [
            'site_identifier' => $site,
            'dataset_key' => $dataset,
        ], ['updated_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Read one fixture checkpoint, decoding its canonical last-applied state.
     *
     * @param   string  $site        Site that owns the dataset.
     * @param   string  $dataset     Dataset key.
     * @param   string  $fixtureKey  Stable fixture key inside the dataset.
     *
     * @return  ?array<string, mixed>  Stored checkpoint, or null before the fixture is first applied.
     *
     * @since   2.0.0
     */
    public function asset(string $site, string $dataset, string $fixtureKey): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND dataset_key = ? AND fixture_key = ?',
            $this->tables->quoted('demo_profile_assets'),
        ), [$site, $dataset, $fixtureKey]);
        if ($row === false) {
            return null;
        }
        $row['last_applied_state'] = $this->decode($row['last_applied_state'] ?? null);

        return $row;
    }

    /**
     * List every resource checkpoint owned by one dataset.
     *
     * @param   string  $site     Site that owns the dataset.
     * @param   string  $dataset  Dataset key.
     *
     * @return  list<array<string, mixed>>  Checkpoints ordered by stable fixture key.
     *
     * @since   2.0.0
     */
    public function assets(string $site, string $dataset): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND dataset_key = ? ORDER BY fixture_key',
            $this->tables->quoted('demo_profile_assets'),
        ), [$site, $dataset]);
        foreach ($rows as &$row) {
            $row['last_applied_state'] = $this->decode($row['last_applied_state'] ?? null);
        }
        unset($row);

        return $rows;
    }

    /**
     * Upsert one resource mapping and the exact canonical state Kumwe most recently applied.
     *
     * @param   string                $site          Site that owns the resource.
     * @param   string                $dataset       Dataset key.
     * @param   string                $fixtureKey    Stable manifest fixture key.
     * @param   string                $resourceType  Closed resource noun used for diagnostics.
     * @param   string                $resourceId    Actual UUID or stable identifier returned by the service.
     * @param   string                $checksum      Canonical checksum of `$state`.
     * @param   int                   $version       Resource version observed after reconciliation.
     * @param   array<string, mixed>  $state         Canonical, non-secret authored state last applied.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordAsset(
        string $site,
        string $dataset,
        string $fixtureKey,
        string $resourceType,
        string $resourceId,
        string $checksum,
        int $version,
        array $state,
    ): void {
        $now = $this->clock->now();
        $existing = $this->asset($site, $dataset, $fixtureKey);
        $values = [
            'resource_id' => $resourceId,
            'resource_type' => $resourceType,
            'last_applied_checksum' => $checksum,
            'last_applied_version' => $version,
            'last_applied_state' => $state,
            'last_applied_at' => $now,
            'updated_at' => $now,
        ];
        $types = [
            'last_applied_version' => Types::BIGINT,
            'last_applied_state' => Types::JSON,
            'last_applied_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        if ($existing !== null) {
            $this->database->update($this->tables->raw('demo_profile_assets'), $values, [
                'site_identifier' => $site,
                'dataset_key' => $dataset,
                'fixture_key' => $fixtureKey,
            ], $types);

            return;
        }

        $this->database->insert($this->tables->raw('demo_profile_assets'), [
            'site_identifier' => $site,
            'dataset_key' => $dataset,
            'fixture_key' => $fixtureKey,
            ...$values,
            'first_applied_at' => $now,
        ], [
            ...$types,
            'first_applied_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Load one dataset selector row.
     *
     * @param   string  $site     Site that owns the dataset.
     * @param   string  $dataset  Dataset key.
     *
     * @return  ?array<string, mixed>  Persisted row or null before first selection.
     *
     * @since   2.0.0
     */
    private function installation(string $site, string $dataset): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND dataset_key = ?',
            $this->tables->quoted('demo_profile_installations'),
        ), [$site, $dataset]);

        return $row === false ? null : $row;
    }

    /**
     * Decode a JSON column returned either as native data or driver text.
     *
     * @param   mixed  $value  Driver value.
     *
     * @return  mixed  Decoded JSON value.
     *
     * @since   2.0.0
     */
    private function decode(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true, 32, JSON_THROW_ON_ERROR) : $value;
    }

    /**
     * Require one positive persisted integer.
     *
     * @param   mixed   $value  Driver value.
     * @param   string  $name   Diagnostic field name.
     *
     * @return  int  Positive integer.
     *
     * @since   2.0.0
     */
    private function integer(mixed $value, string $name): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($integer)) {
            throw new RuntimeException(sprintf('The demo profile %s is invalid.', $name));
        }

        return $integer;
    }

    /**
     * Normalize MySQL integer and PostgreSQL boolean lock results.
     *
     * @param   mixed  $value  Scalar returned by the advisory-lock query.
     *
     * @return  bool  Whether the database reported success.
     *
     * @since   2.0.0
     */
    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
