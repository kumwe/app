<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Doctrine DBAL binding of `TrustStoreRepository`, backed by the extension trust and release tables.
 *
 * Every trust read and write in the installation lands here: the signing keys in `extension_trust_keys`,
 * the counter in `extension_trust_generation`, and the status and release columns that key material
 * governs on `extensions` and `extension_releases`. The part worth knowing about is
 * `synchronizedLifecycle()`, which has no portable primitive behind it — MySQL gets `GET_LOCK`,
 * PostgreSQL a session advisory lock, and every other platform an expiring row in `migration_locks`
 * paired with a process-local nesting counter, because that row is the only one of the three that is not
 * reentrant on its own. All three refuse immediately instead of waiting, so a second lifecycle operation
 * fails fast rather than queueing behind a long install.
 *
 * @since  2.0.0
 */
final readonly class DoctrineTrustStoreRepository implements TrustStoreRepository
{
    /**
     * Row key the durable fallback lock is claimed under in `migration_locks`.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LIFECYCLE_LOCK = 'extension-lifecycle';

    /**
     * Nesting counter that lets the durable fallback lock be entered twice inside one process.
     *
     * @var    ReentrantLifecycleLock
     * @since  2.0.0
     */
    private ReentrantLifecycleLock $localLifecycleLock;

    /**
     * Bind the repository to the trust tables and open its process-local nesting counter.
     *
     * The counter is per instance, so a second repository built on the same connection would not see
     * this one's nesting; the container shares a single instance for exactly that reason.
     *
     * @param  Connection  $database  Connection every trust read, write and lock statement runs on.
     * @param  TableNames  $tables    Resolver for the prefixed trust, release and lock table names.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
        $this->localLifecycleLock = new ReentrantLifecycleLock();
    }

    /**
     * Report whether the trust schema is migrated far enough for lifecycle operations to run.
     *
     * Three things have to line up: the four trust and release tables exist, the generation row records
     * the `ready` lifecycle state, and `extension_releases` carries the artifact, deployed-tree and
     * trust-state columns. A half-migrated installation answers false rather than raising, which is what
     * lets callers fail closed during an upgrade instead of treating a schema gap as an outage.
     *
     * @return  bool  True when the schema and the stored lifecycle state are both complete.
     *
     * @since   2.0.0
     */
    public function lifecycleReady(): bool
    {
        $schema = $this->database->createSchemaManager();
        $required = [
            $this->tables->raw('extension_trust_generation'),
            $this->tables->raw('extension_trust_keys'),
            $this->tables->raw('extension_releases'),
            $this->tables->raw('extension_runtime_outbox'),
        ];
        if (!$schema->tablesExist($required)) {
            return false;
        }
        $releases = $schema->introspectTableByUnquotedName($this->tables->raw('extension_releases'));
        $trustGeneration = $schema->introspectTableByUnquotedName($this->tables->raw('extension_trust_generation'));
        if (
            !$trustGeneration->hasColumn('lifecycle_state')
            || $this->database->fetchOne(sprintf(
                'SELECT lifecycle_state FROM %s WHERE singleton_key = 1',
                $this->tables->quoted('extension_trust_generation'),
            )) !== 'ready'
        ) {
            return false;
        }
        return $releases->hasColumn('artifact_sha256')
            && $releases->hasColumn('deployed_tree_sha256')
            && $releases->hasColumn('trust_state');
    }

    /**
     * Run an operation while holding the installation-wide extension lifecycle lock.
     *
     * MySQL takes `GET_LOCK` with a zero timeout and PostgreSQL `pg_try_advisory_lock`, both keyed on
     * the prefixed table name so two installations sharing a server do not block each other. Everywhere
     * else the lock is a row in `migration_locks` whose unique key provides the exclusion; expired rows
     * are swept first, and the process-local counter is what makes a nested call reuse the row already
     * held instead of colliding with itself. Every path releases in a `finally` block, and none of them
     * waits: a lock another operation holds is refused outright.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Lifecycle work to run while the lock is held.
     *
     * @return  T  Whatever the operation returned, passed back unchanged.
     *
     * @throws  RuntimeException  When another lifecycle operation already holds the lock.
     *
     * @since   2.0.0
     */
    public function synchronizedLifecycle(callable $operation): mixed
    {
        $platform = $this->database->getDatabasePlatform();
        $lockName = 'kumwe:' . $this->tables->raw('extension_lifecycle');
        if ($platform instanceof AbstractMySQLPlatform) {
            $acquired = $this->database->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]);
            if (!in_array($acquired, [1, '1', true], true)) {
                throw new RuntimeException('Another extension lifecycle operation is already in progress.');
            }
            try {
                return $operation();
            } finally {
                $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
        if ($platform instanceof PostgreSQLPlatform) {
            $acquired = $this->database->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', [$lockName]);
            if (!in_array($acquired, [1, '1', true, 't', 'true'], true)) {
                throw new RuntimeException('Another extension lifecycle operation is already in progress.');
            }
            try {
                return $operation();
            } finally {
                $this->database->fetchOne('SELECT pg_advisory_unlock(hashtext(?))', [$lockName]);
            }
        }

        if ($this->localLifecycleLock->held()) {
            $this->localLifecycleLock->enter();
            try {
                return $operation();
            } finally {
                $this->localLifecycleLock->leave();
            }
        }

        $owner = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $table = $this->tables->quoted('migration_locks');
        $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE lock_name = ? AND expires_at <= ?',
            $table,
        ), [self::LIFECYCLE_LOCK, $now], [Types::STRING, Types::DATETIME_IMMUTABLE]);
        try {
            $this->database->insert($this->tables->raw('migration_locks'), [
                'lock_name' => self::LIFECYCLE_LOCK,
                'owner_token' => $owner,
                'acquired_at' => $now,
                'expires_at' => $now->modify('+30 minutes'),
            ], [
                'acquired_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('Another extension lifecycle operation is already in progress.', 0, $exception);
        }
        $this->localLifecycleLock->enter();
        try {
            return $operation();
        } finally {
            $this->localLifecycleLock->leave();
            $this->database->delete($this->tables->raw('migration_locks'), [
                'lock_name' => self::LIFECYCLE_LOCK,
                'owner_token' => $owner,
            ]);
        }
    }

    /**
     * List every trust key on record, revoked and expired ones included.
     *
     * The projection deliberately leaves out `public_key_base64`, so an administration listing never
     * carries key material even when it is rendered somewhere it should not be.
     *
     * @return  list<array<string, mixed>>  One row per key with its scope, enabled flag, expiry and
     *          revocation columns, newest registration first.
     *
     * @since   2.0.0
     */
    public function all(): array
    {
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT key_id, algorithm, enabled, vendor_namespace, extension_pattern, expires_at, rotated_from, '
            . 'added_by, added_at, revoked_at, revoked_by, revocation_reason FROM %s ORDER BY added_at DESC',
            $this->tables->quoted('extension_trust_keys'),
        ));
    }

    /**
     * Insert a trust key record exactly as `TrustStore` assembled it.
     *
     * No field is re-derived or re-checked here; the policy layer has already validated the row, and the
     * only thing this adds is the DBAL typing for the boolean and timestamp columns.
     *
     * @param   array<string, mixed>  $key  Complete key row, keyed by column name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(array $key): void
    {
        $this->database->insert($this->tables->raw('extension_trust_keys'), $key, [
            'enabled' => Types::BOOLEAN,
            'expires_at' => Types::DATETIME_IMMUTABLE,
            'added_at' => Types::DATETIME_IMMUTABLE,
            'revoked_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Withdraw an active key and stamp who revoked it, when, and why.
     *
     * The update only matches a key that is still enabled and not already revoked, and an affected-row
     * count other than one is treated as an error — so a repeated or racing revocation is reported
     * rather than quietly succeeding against a row it did not change.
     *
     * @param   string             $keyId    Identifier of the key to withdraw.
     * @param   string             $actorId  Actor credited with the revocation on the key record.
     * @param   string             $reason   Operator explanation stored alongside the revocation.
     * @param   DateTimeImmutable  $at       Instant recorded as the revocation time.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no enabled, unrevoked key carries that identifier.
     *
     * @since   2.0.0
     */
    public function revoke(string $keyId, string $actorId, string $reason, DateTimeImmutable $at): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET enabled = ?, revoked_at = ?, revoked_by = ?, revocation_reason = ? '
            . 'WHERE key_id = ? AND enabled = ? AND revoked_at IS NULL',
            $this->tables->quoted('extension_trust_keys'),
        ), [false, $at, $actorId, $reason, $keyId, true], [
            Types::BOOLEAN,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::STRING,
            Types::STRING,
            Types::BOOLEAN,
        ]);
        if ($affected !== 1) {
            throw new InvalidArgumentException('The active trust key does not exist.');
        }
    }

    /**
     * Lock the singleton trust generation row `FOR UPDATE` and return the generation in force.
     *
     * The lock lives for the rest of the surrounding transaction, which is what stops a trust mutation
     * and a concurrent runtime trust check from interleaving and observing half a change. Callers are
     * therefore expected to already be inside a transaction when they call it.
     *
     * @return  int  The generation in force for as long as the transaction holds the row.
     *
     * @throws  RuntimeException  When the generation row is absent or does not hold a non-negative integer.
     *
     * @since   2.0.0
     */
    public function lockGeneration(): int
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1 FOR UPDATE',
            $this->tables->quoted('extension_trust_generation'),
        ));
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('The extension trust generation is unavailable.');
        }
        return (int) $value;
    }

    /**
     * Bump the trust generation so anything holding an earlier value knows its view is stale.
     *
     * @param   DateTimeImmutable  $at  Instant recorded as the generation's update time.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the singleton generation row is absent and nothing was updated.
     *
     * @since   2.0.0
     */
    public function advanceGeneration(DateTimeImmutable $at): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET generation = generation + 1, updated_at = ? WHERE singleton_key = 1',
            $this->tables->quoted('extension_trust_generation'),
        ), [$at], [Types::DATETIME_IMMUTABLE]);
        if ($affected !== 1) {
            throw new RuntimeException('The extension trust generation could not be advanced.');
        }
    }

    /**
     * Look up the key that may sign for one extension at one instant.
     *
     * SQL narrows to an enabled, unrevoked, unexpired `ed25519` key with that identifier; the vendor
     * namespace and the extension pattern are then matched in PHP, each accepting `*` as a wildcard. A
     * key that exists but does not cover this extension reads as absent, so the caller cannot tell a
     * scope mismatch from an unknown identifier and has nothing to fall back on either way.
     *
     * @param   string             $keyId                Key identifier named by the package signature.
     * @param   string             $extensionIdentifier  `vendor/name` the signature claims to cover.
     * @param   DateTimeImmutable  $at                   Instant expiry is measured against.
     *
     * @return  array<string, mixed>|null  Key row carrying `public_key_base64` and its scope columns, or
     *          null when no key on record is usable for this extension at this instant.
     *
     * @throws  InvalidArgumentException  When the extension identifier is not in lowercase `vendor/name` form.
     *
     * @since   2.0.0
     */
    public function usable(string $keyId, string $extensionIdentifier, DateTimeImmutable $at): ?array
    {
        $identifier = ExtensionIdentifier::fromString($extensionIdentifier)->value();
        [$vendor, $name] = explode('/', $identifier, 2);
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT key_id, public_key_base64, vendor_namespace, extension_pattern, expires_at FROM %s '
            . 'WHERE key_id = ? AND algorithm = ? AND enabled = ? AND revoked_at IS NULL '
            . 'AND expires_at > ?',
            $this->tables->quoted('extension_trust_keys'),
        ), [$keyId, 'ed25519', true, $at], [
            Types::STRING, Types::STRING, Types::BOOLEAN, Types::DATETIME_IMMUTABLE,
        ]);
        if ($row === false) {
            return null;
        }
        $keyVendor = $row['vendor_namespace'] ?? null;
        $pattern = $row['extension_pattern'] ?? null;
        if (!is_string($keyVendor) || !is_string($pattern)) {
            return null;
        }
        if (($keyVendor !== '*' && $keyVendor !== $vendor) || ($pattern !== '*' && $pattern !== $name)) {
            return null;
        }
        return $row;
    }

    /**
     * Fetch the trust record of the release currently installed for an extension.
     *
     * The join pins `extension_releases` to the version the `extensions` row reports as installed, so an
     * older release row for the same extension is never returned in its place.
     *
     * @param   string  $extensionIdentifier  `vendor/name` of the installed extension.
     *
     * @return  array<string, mixed>|null  Release row with the manifest, the package, artifact and
     *          deployed-tree digests, the signing key, the signature, the trust state and the
     *          `runtime_path` the artifact verifier re-hashes; null when nothing is installed under that
     *          identifier.
     *
     * @since   2.0.0
     */
    public function installedRelease(string $extensionIdentifier): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT e.identifier, e.installed_version, e.service_provider, e.extension_type, e.runtime_path, '
            . 'r.manifest, r.package_sha256, r.signing_key_id, r.signature_base64, r.artifact_sha256, '
            . 'r.deployed_tree_sha256, r.trust_state FROM %s e INNER JOIN %s r '
            . 'ON r.extension_id = e.id AND r.version = e.installed_version WHERE e.identifier = ?',
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ), [$extensionIdentifier]);
        return $row === false ? null : $row;
    }

    /**
     * List the identifiers of every extension currently marked active.
     *
     * @return  list<string>  `vendor/name` identifiers in ascending order; a column value that is not a
     *          string is dropped rather than surfaced, so the list is always usable as-is.
     *
     * @since   2.0.0
     */
    public function activeExtensions(): array
    {
        return array_values(array_filter($this->database->fetchFirstColumn(sprintf(
            "SELECT e.identifier FROM %s e WHERE e.status = 'active' ORDER BY e.identifier",
            $this->tables->quoted('extensions'),
        )), 'is_string'));
    }

    /**
     * List the active extensions whose installed release was signed by one key.
     *
     * This is the blast radius of an emergency revocation: exactly these extensions stop running the
     * moment that key is withdrawn without an upgrade path.
     *
     * @param   string  $keyId  Identifier of the signing key.
     *
     * @return  list<string>  `vendor/name` identifiers in ascending order; empty when the key signs
     *          nothing that is currently active.
     *
     * @since   2.0.0
     */
    public function activeExtensionsForKey(string $keyId): array
    {
        return array_values(array_filter($this->database->fetchFirstColumn(sprintf(
            'SELECT e.identifier FROM %s e INNER JOIN %s r ON r.extension_id = e.id '
            . "AND r.version = e.installed_version WHERE e.status = 'active' AND r.signing_key_id = ? "
            . 'ORDER BY e.identifier',
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ), [$keyId]), 'is_string'));
    }

    /**
     * List the extensions still depending on a key, whatever status they are in.
     *
     * Wider than `activeExtensionsForKey()`: every installed release naming the key counts, except those
     * already quarantined or awaiting reverification. That difference is what makes an orderly
     * revocation refuse while a disabled-but-installed extension would otherwise be stranded with a key
     * it can never re-verify against.
     *
     * @param   string  $keyId  Identifier of the key being retired.
     *
     * @return  list<string>  `vendor/name` identifiers that must be upgraded or quarantined before the
     *          key can be revoked in an orderly way.
     *
     * @since   2.0.0
     */
    public function extensionsRequiringKey(string $keyId): array
    {
        return array_values(array_filter($this->database->fetchFirstColumn(sprintf(
            'SELECT e.identifier FROM %s e INNER JOIN %s r ON r.extension_id = e.id '
            . 'AND r.version = e.installed_version WHERE r.signing_key_id = ? '
            . "AND e.status NOT IN ('quarantined', 'needs_reverification') ORDER BY e.identifier",
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ), [$keyId]), 'is_string'));
    }

    /**
     * Quarantine every active extension signed by a key, and report which ones the sweep covered.
     *
     * The identifier list is taken once, before the first update, and returned whole. An extension that
     * some concurrent change had already moved out of the active status therefore still appears in the
     * result even though nothing was written for it.
     *
     * @param   string             $keyId  Identifier of the key being revoked under emergency.
     * @param   DateTimeImmutable  $at     Instant recorded on each extension as its status changed.
     *
     * @return  list<string>  Identifiers that were active under the key when the sweep started.
     *
     * @since   2.0.0
     */
    public function quarantineExtensionsForKey(string $keyId, DateTimeImmutable $at): array
    {
        $identifiers = $this->activeExtensionsForKey($keyId);
        foreach ($identifiers as $identifier) {
            $this->quarantineExtension($identifier, $at);
        }
        return $identifiers;
    }

    /**
     * Move one active extension into quarantine and bump its registry version.
     *
     * The boolean answer is what stops a repeatedly failing extension from advancing the trust
     * generation on every request: only a real transition out of the active status counts as a change,
     * because the update matches nothing once the extension is already quarantined.
     *
     * @param   string             $extensionIdentifier  `vendor/name` of the extension to withdraw.
     * @param   DateTimeImmutable  $at                   Instant recorded as the status change time.
     *
     * @return  bool  True when the extension was active and is now quarantined; false when it was
     *          already in some other status and nothing was written.
     *
     * @since   2.0.0
     */
    public function quarantineExtension(string $extensionIdentifier, DateTimeImmutable $at): bool
    {
        return $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'quarantined', registry_version = registry_version + 1, updated_at = ? "
            . "WHERE identifier = ? AND status = 'active'",
            $this->tables->quoted('extensions'),
        ), [$at, $extensionIdentifier], [Types::DATETIME_IMMUTABLE, Types::STRING]) === 1;
    }
}
