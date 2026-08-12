<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Doctrine DBAL adapter that keeps users, roles, capability grants and API tokens in the identity tables.
 *
 * This is the only implementation `AccessControlService` runs against, and it is where the two
 * obligations the port states are actually made good. A write that names a stale version or a vanished
 * row is turned into an `InvalidArgumentException` by `assertChanged()` rather than being absorbed as a
 * zero-row success, so a lost update surfaces at the caller. And every change to what a user may do
 * bumps `security_epoch` on their row — inline in the user update, directly on a role assignment or
 * revocation and on a subject-wide token revocation, and once per current member when a role's grants
 * change — which is the mechanism that stops a bearer token minted under the old authority from
 * verifying afterwards.
 *
 * Physical table names come from `TableNames`, quoted for the platform, so the prefix never reaches a
 * statement unvalidated. Paged reads are capped at 500 rows because the window is interpolated into the
 * SQL rather than bound. Locking is plain `SELECT ... FOR UPDATE`: this class opens no transaction of
 * its own, so the caller must already be inside one for a lock or a multi-statement write to mean
 * anything. Stored JSON capability inventories are decoded and checked element by element here, so a
 * corrupted row fails at this boundary instead of travelling on as an untyped value.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAccessControlRepository implements AccessControlRepository
{
    /**
     * Bind the adapter to the connection it runs on and the table names it resolves through.
     *
     * @param  Connection  $database  DBAL connection every statement uses; transactions belong to the caller.
     * @param  TableNames  $tables    Resolver for prefixed physical table names, quoted for this platform.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Read one page of users, each with the roles assigned to it.
     *
     * Rows come back unfiltered, as the port requires: judging who may see which user is the service's
     * job. Ordering is display name, then email, then identifier, so paging is stable when names repeat.
     * Roles are collected with one extra query per user rather than a join, which keeps a user to one row
     * at the cost of a query per row in the page.
     *
     * @param   int  $limit   Maximum rows to read in this page, from 1 to 500.
     * @param   int  $offset  Rows to skip before collecting the page.
     *
     * @return  list<array<string, mixed>>  One row per user carrying its columns plus a `roles` key whose
     *          value lists the role id, code and name, ordered by role name.
     *
     * @throws  InvalidArgumentException  When the limit is outside 1 to 500 or the offset is negative.
     *
     * @since   2.0.0
     */
    public function users(int $limit = 100, int $offset = 0): array
    {
        $this->assertPage($limit, $offset);
        $users = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, email, display_name, status, version, created_at, updated_at FROM %s '
            . 'ORDER BY display_name, email, id LIMIT %d OFFSET %d',
            $this->tables->quoted('users'),
            $limit,
            $offset,
        ));

        foreach ($users as &$user) {
            $user['roles'] = $this->database->fetchAllAssociative(sprintf(
                'SELECT r.id, r.code, r.name FROM %s r INNER JOIN %s ur ON ur.role_id = r.id '
                . 'WHERE ur.user_id = ? ORDER BY r.name',
                $this->tables->quoted('roles'),
                $this->tables->quoted('user_roles'),
            ), [$user['id']]);
        }
        unset($user);

        return $users;
    }

    /**
     * Read one page of roles, each with the capability grants attached to it.
     *
     * Grants are collected per role with an extra query, the same shape `users()` uses, and are ordered
     * by capability code so a role's authority reads the same way on every request.
     *
     * @param   int  $limit   Maximum rows to read in this page, from 1 to 500.
     * @param   int  $offset  Rows to skip before collecting the page.
     *
     * @return  list<array<string, mixed>>  One row per role carrying its columns plus a `grants` key whose
     *          value lists the grant id, capability, scope type and scope identifier.
     *
     * @throws  InvalidArgumentException  When the limit is outside 1 to 500 or the offset is negative.
     *
     * @since   2.0.0
     */
    public function roles(int $limit = 100, int $offset = 0): array
    {
        $this->assertPage($limit, $offset);
        $roles = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, code, name, created_at FROM %s ORDER BY name, id LIMIT %d OFFSET %d',
            $this->tables->quoted('roles'),
            $limit,
            $offset,
        ));

        foreach ($roles as &$role) {
            $role['grants'] = $this->database->fetchAllAssociative(sprintf(
                'SELECT id, capability_code AS capability, scope_type, scope_identifier FROM %s '
                . 'WHERE role_id = ? ORDER BY capability_code',
                $this->tables->quoted('role_capability_grants'),
            ), [$role['id']]);
        }
        unset($role);

        return $roles;
    }

    /**
     * Read one page of the capability vocabulary a grant may name.
     *
     * Straight off the `capabilities` table the migrations populate, ordered by code. It says what may be
     * granted, never what any role currently holds.
     *
     * @param   int  $limit   Maximum rows to read in this page, from 1 to 500.
     * @param   int  $offset  Rows to skip before collecting the page.
     *
     * @return  list<array{code: string, description: string}>  Capability codes with their operator-facing
     *          text, in ascending code order.
     *
     * @throws  InvalidArgumentException  When the limit is outside 1 to 500 or the offset is negative.
     *
     * @since   2.0.0
     */
    public function capabilities(int $limit = 100, int $offset = 0): array
    {
        $this->assertPage($limit, $offset);
        /** @var list<array{code: string, description: string}> $rows */
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT code, description FROM %s ORDER BY code LIMIT %d OFFSET %d',
            $this->tables->quoted('capabilities'),
            $limit,
            $offset,
        ));

        return $rows;
    }

    /**
     * Read one page of the API tokens issued for one site, newest first.
     *
     * The site filter is part of the statement, not a post-filter, so another site's tokens are never
     * fetched. Only metadata is selected — the digest column stays behind — and the subject is joined in
     * so a listing needs no second lookup. Each row's stored capability inventory is decoded from JSON
     * and every element checked, so a corrupted row is rejected here rather than reaching the caller as a
     * raw string.
     *
     * @param   string  $siteIdentifier  Site whose tokens are listed; another site's tokens never appear.
     * @param   int     $limit           Maximum rows to read in this page, from 1 to 500.
     * @param   int     $offset          Rows to skip before collecting the page.
     *
     * @return  list<array<string, mixed>>  Newest first, each row carrying the token's life and scope, its
     *          subject columns, and a `capabilities` key holding a list of capability codes.
     *
     * @throws  InvalidArgumentException  When the page window is invalid, or a stored token's capabilities
     *          are not valid JSON, not a list, or hold a non-string entry.
     *
     * @since   2.0.0
     */
    public function tokens(string $siteIdentifier, int $limit = 100, int $offset = 0): array
    {
        $this->assertPage($limit, $offset);
        $tokens = $this->database->fetchAllAssociative(sprintf(
            'SELECT t.id, t.name, t.capabilities, t.security_epoch, t.audience, t.purpose, t.site_identifier, '
            . 't.rotated_from, t.expires_at, t.revoked_at, t.revocation_reason, t.created_at, t.last_used_at, '
            . 'u.id AS subject_id, u.email AS subject_email, u.display_name AS subject_name '
            . 'FROM %s t INNER JOIN %s u ON u.id = t.subject_id '
            . 'WHERE t.site_identifier = ? ORDER BY t.created_at DESC, t.id DESC LIMIT %d OFFSET %d',
            $this->tables->quoted('api_tokens'),
            $this->tables->quoted('users'),
            $limit,
            $offset,
        ), [$siteIdentifier]);

        foreach ($tokens as &$token) {
            $capabilities = $token['capabilities'] ?? null;
            if (is_string($capabilities)) {
                try {
                    $capabilities = json_decode($capabilities, true, 32, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException('A stored token has invalid capabilities.', 0, $exception);
                }
            }
            if (!is_array($capabilities) || !array_is_list($capabilities)) {
                throw new InvalidArgumentException('A stored token has invalid capabilities.');
            }
            foreach ($capabilities as $capability) {
                if (!is_string($capability)) {
                    throw new InvalidArgumentException('A stored token has invalid capabilities.');
                }
            }
            $token['capabilities'] = $capabilities;
        }
        unset($token);

        return $tokens;
    }

    /**
     * Read one page of installation identity and credential audit events, newest first.
     *
     * The closed action-prefix filter prevents the identity screen from becoming a general audit export.
     * Metadata is never selected, which keeps credential purpose, recovery, reason, and policy context out
     * of the graphical timeline while retaining accountable actor, target, outcome, and time.
     *
     * @param   int  $limit   Maximum rows to read in this page, from 1 to 500.
     * @param   int  $offset  Rows to skip before collecting the page.
     *
     * @return  list<array<string, mixed>>  Newest identity-related events without stored metadata.
     *
     * @throws  InvalidArgumentException  When the page window is invalid.
     *
     * @since   2.0.0
     */
    public function securityEvents(int $limit = 100, int $offset = 0): array
    {
        $this->assertPage($limit, $offset);

        return $this->database->fetchAllAssociative(sprintf(
            'SELECT id, occurred_at, actor_id, action, subject_type, subject_id, outcome FROM %s '
            . "WHERE action = 'administrator.create' OR action LIKE 'user.%%' OR action LIKE 'role.%%' "
            . "OR action LIKE 'capability.%%' OR action LIKE 'token.%%' OR action LIKE 'identity.step_up.%%' "
            . 'ORDER BY occurred_at DESC, id DESC LIMIT %d OFFSET %d',
            $this->tables->quoted('audit_events'),
            $limit,
            $offset,
        ));
    }

    /**
     * Write a new user row together with the password credential it signs in with.
     *
     * The address is stored twice, as given and as the normalised lookup key, and the account starts at
     * version 1 and security epoch 1. Two inserts are issued without a transaction of their own, so the
     * caller must supply one for the user and its credential to land together.
     *
     * @param   string             $id            UUID to store the user under.
     * @param   string             $email         Address the user signs in with, already normalised.
     * @param   string             $displayName   Human-readable name shown wherever the user is listed.
     * @param   string             $status        Lifecycle status to start at, as a `UserStatus` value.
     * @param   string             $passwordHash  Already-hashed password; plaintext never reaches this adapter.
     * @param   DateTimeImmutable  $at            Instant recorded as the creation, last-change and credential
     *          change time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertUser(
        string $id,
        string $email,
        string $displayName,
        string $status,
        string $passwordHash,
        DateTimeImmutable $at,
    ): void {
        $this->database->insert($this->tables->raw('users'), [
            'id' => $id,
            'email' => $email,
            'email_normalized' => $email,
            'display_name' => $displayName,
            'status' => $status,
            'version' => 1,
            'security_epoch' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->database->insert($this->tables->raw('password_credentials'), [
            'user_id' => $id,
            'password_hash' => $passwordHash,
            'changed_at' => $at,
        ], [
            'changed_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Read the lifecycle status stored for one user.
     *
     * A single-column read with no lock of its own; callers that intend to act on the answer take
     * `lockUser()` first so the status cannot move underneath the decision.
     *
     * @param   string  $userId  UUID of the user whose stored status is read.
     *
     * @return  ?string  The stored status value, or null when no such user exists or the column does not
     *          hold a string.
     *
     * @since   2.0.0
     */
    public function userStatus(string $userId): ?string
    {
        $status = $this->database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE id = ?',
            $this->tables->quoted('users'),
        ), [$userId]);

        return is_string($status) ? $status : null;
    }

    /**
     * Apply an edited user record, refusing the write when someone else has moved it on.
     *
     * The expected version sits in the `WHERE` clause, so the row changes only while it still carries the
     * version the caller read; a mismatch affects no rows and is raised rather than ignored. The same
     * statement advances `version` and `security_epoch` together, which is what makes tokens minted
     * before the edit stop verifying.
     *
     * @param   string             $id               UUID of the user being edited.
     * @param   string             $email            Replacement address, already normalised; written to both
     *          the display and lookup columns.
     * @param   string             $displayName      Replacement human-readable name.
     * @param   string             $status           Replacement lifecycle status, as a `UserStatus` value.
     * @param   int                $expectedVersion  Version the caller read; the write applies only at it.
     * @param   DateTimeImmutable  $at               Instant recorded as the last-change time.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no row matched, meaning the user vanished or was edited
     *          concurrently and the caller must reload before retrying.
     *
     * @since   2.0.0
     */
    public function updateUser(
        string $id,
        string $email,
        string $displayName,
        string $status,
        int $expectedVersion,
        DateTimeImmutable $at,
    ): void {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET email = ?, email_normalized = ?, display_name = ?, status = ?, '
            . 'version = version + 1, security_epoch = security_epoch + 1, updated_at = ? '
            . 'WHERE id = ? AND version = ?',
            $this->tables->quoted('users'),
        ), [$email, $email, $displayName, $status, $at, $id, $expectedVersion], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::INTEGER,
        ]);
        $this->assertChanged($affected, 'user');
    }

    /**
     * Write a new role row for capability grants to hang from.
     *
     * The role is created bare: it confers nothing until `grant()` attaches capabilities to it.
     *
     * @param   string             $id    UUID to store the role under.
     * @param   string             $code  Stable lowercase identifier policy refers to the role by.
     * @param   string             $name  Human-readable name shown in the administrator.
     * @param   DateTimeImmutable  $at    Instant recorded as the creation time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertRole(string $id, string $code, string $name, DateTimeImmutable $at): void
    {
        $this->database->insert($this->tables->raw('roles'), [
            'id' => $id, 'code' => $code, 'name' => $name, 'created_at' => $at,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Give a user a role unless they already hold it.
     *
     * The existing assignment is looked up first, so a replay inserts nothing and leaves the security
     * epoch alone; only an assignment that actually lands advances it, because that is the moment the
     * user's authority widens. The read and the insert are not atomic on their own, so the caller holds
     * the user and role locks around the pair.
     *
     * @param   string             $userId   UUID of the user gaining the role.
     * @param   string             $roleId   UUID of the role being assigned.
     * @param   string             $actorId  UUID of the administrator assigning it, kept for the audit trail.
     * @param   DateTimeImmutable  $at       Instant recorded as the assignment time.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the assignment landed but the user row could not be found to
     *          advance its security epoch.
     *
     * @since   2.0.0
     */
    public function assignRole(string $userId, string $roleId, string $actorId, DateTimeImmutable $at): void
    {
        $exists = $this->database->fetchOne(sprintf(
            'SELECT role_id FROM %s WHERE user_id = ? AND role_id = ?',
            $this->tables->quoted('user_roles'),
        ), [$userId, $roleId]);

        if ($exists === false) {
            $this->database->insert($this->tables->raw('user_roles'), [
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_at' => $at,
                'assigned_by' => $actorId,
            ], [
                'assigned_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->incrementSecurityEpoch($userId);
        }
    }

    /**
     * Take a role away from a user.
     *
     * Unlike assignment this is not idempotent: the delete must remove exactly one row, so revoking a
     * role the user does not hold is reported rather than passed over. The epoch bump follows, so tokens
     * minted while they still held the role stop verifying.
     *
     * @param   string  $userId  UUID of the user losing the role.
     * @param   string  $roleId  UUID of the role being taken away.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the user did not hold that role, or when the user row could
     *          not be found to advance its security epoch.
     *
     * @since   2.0.0
     */
    public function revokeRole(string $userId, string $roleId): void
    {
        $this->assertChanged(
            $this->database->delete($this->tables->raw('user_roles'), ['user_id' => $userId, 'role_id' => $roleId]),
            'role assignment',
        );
        $this->incrementSecurityEpoch($userId);
    }

    /**
     * Attach a capability to a role, globally or within one named scope.
     *
     * Whether the actor may confer this is settled before the call; the adapter writes the row it is
     * given. Because the role's members immediately gain the capability, every current member has their
     * security epoch advanced, so a token they already hold cannot silently pick it up.
     *
     * @param   string             $id               UUID to store the grant under.
     * @param   string             $roleId           UUID of the role receiving the capability.
     * @param   string             $capability       Capability code being granted.
     * @param   string             $scopeType        Kind of scope it applies in, `global` or a named kind.
     * @param   ?string            $scopeIdentifier  Which instance of that kind, or null for a global grant.
     * @param   string             $actorId          UUID of the administrator granting it, kept for audit.
     * @param   DateTimeImmutable  $at               Instant recorded as the grant time.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a member's user row could not be found to advance its
     *          security epoch.
     *
     * @since   2.0.0
     */
    public function grant(
        string $id,
        string $roleId,
        string $capability,
        string $scopeType,
        ?string $scopeIdentifier,
        string $actorId,
        DateTimeImmutable $at,
    ): void {
        $this->database->insert($this->tables->raw('role_capability_grants'), [
            'id' => $id,
            'role_id' => $roleId,
            'capability_code' => $capability,
            'scope_type' => $scopeType,
            'scope_identifier' => $scopeIdentifier,
            'granted_at' => $at,
            'granted_by' => $actorId,
        ], [
            'granted_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->incrementRoleMembersEpoch($roleId);
    }

    /**
     * Remove a capability grant from the role that holds it.
     *
     * The owning role is read before the delete, because afterwards there is nothing left to join
     * through; its members are then invalidated so a token that already carried the capability loses it
     * rather than keeping it until expiry. When the role could not be read the delete still stands and
     * only the epoch bump is skipped.
     *
     * @param   string  $grantId  UUID of the grant to remove.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no grant carries that identifier, or when a member's user row
     *          could not be found to advance its security epoch.
     *
     * @since   2.0.0
     */
    public function revokeGrant(string $grantId): void
    {
        $roleId = $this->database->fetchOne(sprintf(
            'SELECT role_id FROM %s WHERE id = ?',
            $this->tables->quoted('role_capability_grants'),
        ), [$grantId]);
        $this->assertChanged(
            $this->database->delete($this->tables->raw('role_capability_grants'), ['id' => $grantId]),
            'capability grant',
        );
        if (is_string($roleId)) {
            $this->incrementRoleMembersEpoch($roleId);
        }
    }

    /**
     * Mark one live API token as revoked.
     *
     * The row is stamped rather than deleted, so the token's history and the reason it ended survive for
     * audit. The `revoked_at IS NULL` guard makes this a one-shot: revoking an already-revoked token
     * affects nothing and is reported as a failure rather than treated as success.
     *
     * @param   string             $tokenId  UUID of the token to revoke.
     * @param   DateTimeImmutable  $at       Instant recorded as the revocation time.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the token does not exist or was already revoked.
     *
     * @since   2.0.0
     */
    public function revokeToken(string $tokenId, DateTimeImmutable $at): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$at, $tokenId], [Types::DATETIME_IMMUTABLE, Types::STRING]);
        $this->assertChanged($affected, 'active API token');
    }

    /**
     * Resolve a normalised email address to the user allowed to sign in with it.
     *
     * The `active` status is part of the statement, so a suspended, pending or disabled account simply
     * does not answer; that is what stops a token being issued for a subject who may no longer sign in.
     *
     * @param   string  $normalizedEmail  Address already put through `EmailAddress` normalisation.
     *
     * @return  ?string  UUID of the active user, or null when no active account holds that address.
     *
     * @since   2.0.0
     */
    public function userIdByEmail(string $normalizedEmail): ?string
    {
        $id = $this->database->fetchOne(sprintf(
            "SELECT id FROM %s WHERE email_normalized = ? AND status = 'active'",
            $this->tables->quoted('users'),
        ), [$normalizedEmail]);

        return is_string($id) ? $id : null;
    }

    /**
     * Read which site a token belongs to.
     *
     * Callers compare the answer against their own site before acting, so a token cannot be revoked or
     * rotated from a site that does not own it.
     *
     * @param   string  $tokenId  UUID of the token being located.
     *
     * @return  ?string  Site identifier the token is confined to, or null when no such token exists.
     *
     * @since   2.0.0
     */
    public function tokenSite(string $tokenId): ?string
    {
        $site = $this->database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE id = ?',
            $this->tables->quoted('api_tokens'),
        ), [$tokenId]);
        return is_string($site) ? $site : null;
    }

    /**
     * Read everything a rotation must copy from a token that is still usable.
     *
     * Usability is decided in SQL and is stricter than mere existence: the token must be unrevoked and
     * unexpired, still sitting on its subject's current security epoch, and owned by an account whose
     * status is `active`. A token that fails any of those reads as absent. With the lock requested the
     * joined rows are held for the rest of the caller's transaction, which is how rotation stops the
     * record being revoked between this read and the replacement it writes.
     *
     * @param   string  $tokenId  UUID of the token about to be rotated.
     * @param   bool    $lock     Whether to append `FOR UPDATE` and hold the rows for the transaction.
     *
     * @return  array{subject_id: string, email: string, capabilities: list<string>, site_identifier: string,
     *          audience: string, purpose: string, organization_identifier: ?string, workspace_identifier: ?string,
     *          membership_id: ?string, membership_version: ?int, policy_generation: ?int, family_id: string,
     *          delegation_depth: int, expires_at: DateTimeImmutable}|null  Null when absent or unusable.
     *
     * @throws  JsonException  When the stored capability inventory is not decodable JSON; unlike
     *          `tokens()`, this path lets the decoder error propagate.
     * @throws  RuntimeException  When the inventory decodes to something other than a list of strings, or
     *          a joined column that must be text is not.
     *
     * @since   2.0.0
     */
    public function activeTokenForRotation(string $tokenId, bool $lock = false): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT t.subject_id, u.email, t.capabilities, t.site_identifier, t.audience, t.purpose, '
            . 't.organization_identifier, t.workspace_identifier, t.membership_id, t.membership_version, '
            . 't.policy_generation, t.family_id, t.delegation_depth, t.expires_at FROM %s t '
            . 'INNER JOIN %s u ON u.id = t.subject_id WHERE t.id = ? AND t.revoked_at IS NULL '
            . 'AND t.security_epoch = u.security_epoch '
            . "AND t.expires_at > CURRENT_TIMESTAMP AND u.status = 'active'%s",
            $this->tables->quoted('api_tokens'),
            $this->tables->quoted('users'),
            $lock && !($this->database->getDatabasePlatform() instanceof SQLitePlatform) ? ' FOR UPDATE' : '',
        ), [$tokenId]);
        if ($row === false) {
            return null;
        }
        $capabilities = $row['capabilities'] ?? null;
        if (is_string($capabilities)) {
            $capabilities = json_decode($capabilities, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($capabilities) || !array_is_list($capabilities)) {
            throw new RuntimeException('The token capability inventory is invalid.');
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new RuntimeException('The token capability inventory is invalid.');
            }
        }
        $subjectId = $row['subject_id'] ?? null;
        $email = $row['email'] ?? null;
        $site = $row['site_identifier'] ?? null;
        $audience = $row['audience'] ?? null;
        $purpose = $row['purpose'] ?? null;
        $organization = $row['organization_identifier'] ?? null;
        $workspace = $row['workspace_identifier'] ?? null;
        $membershipId = $row['membership_id'] ?? null;
        $storedExpiry = $row['expires_at'] ?? null;
        try {
            $expiresAt = $storedExpiry instanceof DateTimeImmutable
                ? $storedExpiry
                : (is_string($storedExpiry) ? new DateTimeImmutable($storedExpiry) : null);
        } catch (\Exception $exception) {
            throw new RuntimeException('The active token expiry is invalid.', 0, $exception);
        }
        if (
            !is_string($subjectId)
            || !is_string($email)
            || !is_string($site)
            || !is_string($audience)
            || !is_string($purpose)
            || ($organization !== null && !is_string($organization))
            || ($workspace !== null && !is_string($workspace))
            || ($membershipId !== null && !is_string($membershipId))
            || !$expiresAt instanceof DateTimeImmutable
        ) {
            throw new RuntimeException('The active token rotation record is invalid.');
        }
        return [
            'subject_id' => $subjectId,
            'email' => $email,
            'capabilities' => $capabilities,
            'site_identifier' => $site,
            'audience' => $audience,
            'purpose' => $purpose,
            'organization_identifier' => $organization,
            'workspace_identifier' => $workspace,
            'membership_id' => $membershipId,
            'membership_version' => $this->nullablePositiveInteger($row['membership_version'] ?? null),
            'policy_generation' => $this->nullablePositiveInteger($row['policy_generation'] ?? null),
            'family_id' => is_string($row['family_id'] ?? null) ? $row['family_id'] : $tokenId,
            'delegation_depth' => $this->nonNegativeInteger($row['delegation_depth'] ?? 0),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Revoke every live token a subject holds, in every site, as a break-glass measure.
     *
     * The security epoch is advanced first, so even a token the update somehow misses fails its epoch
     * check on the next request; that ordering also means the whole call fails when the subject does not
     * exist, before any token is touched.
     *
     * @param   string             $userId  UUID of the subject whose tokens are all being destroyed.
     * @param   DateTimeImmutable  $at      Instant recorded as the revocation time.
     * @param   string             $reason  Operator-supplied justification stored beside each token.
     *
     * @return  int  How many live tokens were revoked, zero when the subject held none.
     *
     * @throws  InvalidArgumentException  When no user row carries that identifier, so the epoch could not
     *          be advanced.
     *
     * @since   2.0.0
     */
    public function revokeSubjectTokens(string $userId, DateTimeImmutable $at, string $reason): int
    {
        $this->incrementSecurityEpoch($userId);
        return (int) $this->database->executeStatement(sprintf(
            'UPDATE %s SET revoked_at = ?, revocation_reason = ? WHERE subject_id = ? AND revoked_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$at, $reason, $userId], [Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID]);
    }

    /**
     * Revoke the live tokens a subject holds for one site, leaving their other sites alone.
     *
     * The contained counterpart of `revokeSubjectTokens()`: it deliberately does not touch the security
     * epoch, because doing so would invalidate the subject's credentials everywhere else too.
     *
     * @param   string             $userId          UUID of the subject whose tokens are being revoked.
     * @param   string             $siteIdentifier  Site whose tokens are revoked; others are untouched.
     * @param   DateTimeImmutable  $at              Instant recorded as the revocation time.
     * @param   string             $reason          Operator-supplied justification stored beside each token.
     *
     * @return  int  How many live tokens were revoked, zero when the subject held none for that site.
     *
     * @since   2.0.0
     */
    public function revokeSubjectTokensForSite(
        string $userId,
        string $siteIdentifier,
        DateTimeImmutable $at,
        string $reason,
    ): int {
        return (int) $this->database->executeStatement(sprintf(
            'UPDATE %s SET revoked_at = ?, revocation_reason = ? '
            . 'WHERE subject_id = ? AND site_identifier = ? AND revoked_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$at, $reason, $userId, $siteIdentifier], [
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::GUID,
            Types::STRING,
        ]);
    }

    /**
     * Hold the user's row for the rest of the caller's transaction.
     *
     * The lock is taken by selecting `security_epoch` `FOR UPDATE`, and the value read back is what
     * proves the row exists: a missing user yields no epoch and is refused. Outside a transaction the
     * lock is released immediately, so this is only meaningful inside one.
     *
     * @param   string  $userId  UUID of the user to lock.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no row answered, so the user does not exist or could not be
     *          locked.
     *
     * @since   2.0.0
     */
    public function lockUser(string $userId): void
    {
        $epoch = $this->database->fetchOne(sprintf(
            'SELECT security_epoch FROM %s WHERE id = ? FOR UPDATE',
            $this->tables->quoted('users'),
        ), [$userId]);
        if (!is_int($epoch) && (!is_string($epoch) || preg_match('/^[0-9]+$/D', $epoch) !== 1)) {
            throw new InvalidArgumentException('The user does not exist or could not be locked.');
        }
    }

    /**
     * Hold the role's row for the rest of the caller's transaction.
     *
     * Role assignment and granting lock here first, so the delegation check cannot be made against grants
     * another administrator changes before the write lands.
     *
     * @param   string  $roleId  UUID of the role to lock.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no row answered, so the role does not exist or could not be
     *          locked.
     *
     * @since   2.0.0
     */
    public function lockRole(string $roleId): void
    {
        $id = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ? FOR UPDATE',
            $this->tables->quoted('roles'),
        ), [$roleId]);
        if (!is_string($id)) {
            throw new InvalidArgumentException('The role does not exist or could not be locked.');
        }
    }

    /**
     * Read the stable code policy identifies a role by.
     *
     * The access-control service uses it to recognise the `administrator` role, which an actor may not
     * strip from themselves.
     *
     * @param   string  $roleId  UUID of the role being inspected.
     *
     * @return  ?string  The role's code, or null when no such role exists.
     *
     * @since   2.0.0
     */
    public function roleCode(string $roleId): ?string
    {
        $code = $this->database->fetchOne(sprintf(
            'SELECT code FROM %s WHERE id = ?',
            $this->tables->quoted('roles'),
        ), [$roleId]);

        return is_string($code) ? $code : null;
    }

    /**
     * Read the capabilities one role confers, with the scope each applies in.
     *
     * The delegation check walks this list before a role is handed on, measuring each grant it finds
     * against what the actor may themselves delegate. Ordering is capability, then scope type, then
     * scope identifier, so the same role always presents its authority in the same order.
     *
     * @param   string  $roleId  UUID of the role being inspected.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>  Empty when
     *          the role confers nothing.
     *
     * @since   2.0.0
     */
    public function roleGrants(string $roleId): array
    {
        /** @var list<array{capability: string, scope_type: string, scope_identifier: ?string}> $rows */
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT capability_code AS capability, scope_type, scope_identifier FROM %s '
            . 'WHERE role_id = ? ORDER BY capability_code, scope_type, scope_identifier',
            $this->tables->quoted('role_capability_grants'),
        ), [$roleId]);

        return $rows;
    }

    /**
     * Read the identified grant snapshot a role-scoped change set compares and edits.
     *
     * @param   string  $roleId  UUID of the role whose grants are being edited.
     *
     * @return  list<array{id: string, capability: string, scope_type: string, scope_identifier: ?string}>
     *          Stable grant rows including their immutable identifiers.
     *
     * @since   2.0.0
     */
    public function roleGrantRecords(string $roleId): array
    {
        /**
         * @var    list<array{id: string, capability: string, scope_type: string, scope_identifier: ?string}>  $rows
         */
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, capability_code AS capability, scope_type, scope_identifier FROM %s '
            . 'WHERE role_id = ? ORDER BY capability_code, scope_type, scope_identifier, id',
            $this->tables->quoted('role_capability_grants'),
        ), [$roleId]);

        return $rows;
    }

    /**
     * Read every capability a user holds through their roles, with the scope each applies in.
     *
     * The statement joins the user's roles to their grants and deduplicates, so a capability conferred by
     * two roles appears once. Token issuance checks each requested capability against this list, which is
     * what keeps a token from carrying more than its subject already has.
     *
     * @param   string  $userId  UUID of the user being inspected.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>  Empty when
     *          the user holds nothing.
     *
     * @since   2.0.0
     */
    public function userGrants(string $userId): array
    {
        /** @var list<array{capability: string, scope_type: string, scope_identifier: ?string}> $rows */
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT DISTINCT g.capability_code AS capability, g.scope_type, g.scope_identifier '
            . 'FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id WHERE ur.user_id = ? '
            . 'ORDER BY g.capability_code, g.scope_type, g.scope_identifier',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
        ), [$userId]);

        return $rows;
    }

    /**
     * Resolve live membership-role authority for one exact organization and optional workspace.
     *
     * @param   string   $userId                  User whose membership authority is being verified.
     * @param   string   $siteIdentifier          Site that must own the organization.
     * @param   string   $organizationIdentifier  Exact organization selected for the token.
     * @param   ?string  $workspaceIdentifier     Optional exact workspace selected for the token.
     * @param   bool     $lock                    Whether to lock the membership snapshot for token issuance.
     *
     * @return  ?array{membership_id: string, membership_version: int, policy_generation: int,
     *          organization_identifier: string, workspace_identifier: ?string,
     *          grants: list<array{capability: string, scope_type: string, scope_identifier: ?string}>}
     *          Exact membership authority, or null when the requested context is unavailable.
     *
     * @since   2.0.0
     */
    public function organizationMembershipAuthority(
        string $userId,
        string $siteIdentifier,
        string $organizationIdentifier,
        ?string $workspaceIdentifier,
        bool $lock = false,
    ): ?array {
        $parameters = [$userId, $siteIdentifier, $organizationIdentifier];
        $sql = sprintf(
            'SELECT m.id, m.version, o.policy_generation FROM %s m '
            . 'INNER JOIN %s o ON o.id = m.organization_id '
            . "WHERE m.user_id = ? AND m.status = 'active' AND m.valid_from <= CURRENT_TIMESTAMP "
            . 'AND (m.valid_until IS NULL OR m.valid_until > CURRENT_TIMESTAMP) '
            . "AND o.site_identifier = ? AND o.identifier = ? AND o.status = 'active' ",
            $this->tables->quoted('organization_memberships'),
            $this->tables->quoted('organizations'),
        );
        if ($workspaceIdentifier !== null) {
            $sql .= sprintf(
                'AND EXISTS (SELECT 1 FROM %s mw INNER JOIN %s w ON w.id = mw.workspace_id '
                . 'WHERE mw.membership_id = m.id AND w.organization_id = o.id AND w.identifier = ? '
                . "AND w.status = 'active') ",
                $this->tables->quoted('membership_workspaces'),
                $this->tables->quoted('workspaces'),
            );
            $parameters[] = $workspaceIdentifier;
        }
        if (
            $lock
            && !($this->database->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform)
        ) {
            $sql .= 'FOR UPDATE';
        }
        $row = $this->database->fetchAssociative($sql, $parameters);
        if ($row === false) {
            return null;
        }
        $membershipId = $row['id'] ?? null;
        if (!is_string($membershipId)) {
            throw new RuntimeException('A stored organization membership identity is invalid.');
        }
        /** @var list<array{capability: string, scope_type: string, scope_identifier: ?string}> $grants */
        $grants = $this->database->fetchAllAssociative(sprintf(
            'SELECT DISTINCT g.capability_code AS capability, g.scope_type, g.scope_identifier '
            . 'FROM %s mr INNER JOIN %s g ON g.role_id = mr.role_id WHERE mr.membership_id = ? '
            . 'ORDER BY g.capability_code, g.scope_type, g.scope_identifier',
            $this->tables->quoted('membership_roles'),
            $this->tables->quoted('role_capability_grants'),
        ), [$membershipId]);

        return [
            'membership_id' => $membershipId,
            'membership_version' => $this->nullablePositiveInteger($row['version'] ?? null)
                ?? throw new RuntimeException('A stored membership version is invalid.'),
            'policy_generation' => $this->nullablePositiveInteger($row['policy_generation'] ?? null)
                ?? throw new RuntimeException('A stored organization policy generation is invalid.'),
            'organization_identifier' => $organizationIdentifier,
            'workspace_identifier' => $workspaceIdentifier,
            'grants' => $grants,
        ];
    }

    /**
     * Read one capability grant by its identifier.
     *
     * Revocation reads the grant first so it can authorize against the role that holds it. The owning
     * role, capability and scope type are asserted to be text and the scope identifier to be text or
     * null, so a grant row that has lost a column is refused instead of being partially believed.
     *
     * @param   string  $grantId  UUID of the grant being read.
     *
     * @return  array{role_id: string, capability: string, scope_type: string, scope_identifier: ?string}|null
     *          Null when no grant carries that identifier.
     *
     * @throws  InvalidArgumentException  When the stored row's role, capability or scope type is not text,
     *          or its scope identifier is neither text nor null.
     *
     * @since   2.0.0
     */
    public function grantRecord(string $grantId): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT role_id, capability_code AS capability, scope_type, scope_identifier FROM %s WHERE id = ?',
            $this->tables->quoted('role_capability_grants'),
        ), [$grantId]);

        if ($row === false) {
            return null;
        }

        foreach (['role_id', 'capability', 'scope_type'] as $field) {
            if (!is_string($row[$field] ?? null)) {
                throw new InvalidArgumentException('A stored capability grant is invalid.');
            }
        }
        $scopeIdentifier = $row['scope_identifier'] ?? null;
        if ($scopeIdentifier !== null && !is_string($scopeIdentifier)) {
            throw new InvalidArgumentException('A stored capability grant scope is invalid.');
        }

        return [
            'role_id' => $row['role_id'],
            'capability' => $row['capability'],
            'scope_type' => $row['scope_type'],
            'scope_identifier' => $scopeIdentifier,
        ];
    }

    /**
     * Refuse a write that did not change exactly one row.
     *
     * This is where the port's promise that a stale or vanished target fails loudly is kept. DBAL reports
     * affected rows as an integer on some drivers and a numeric string on others, so the comparison is
     * made on the string form; anything but one row means the target was gone or had already moved on.
     *
     * @param   int|string  $affected  Rows the statement reported as affected.
     * @param   string      $resource  Name of the thing being written, read back to the operator in the
     *          failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the statement did not affect exactly one row.
     *
     * @since   2.0.0
     */
    private function assertChanged(int|string $affected, string $resource): void
    {
        if ((string) $affected !== '1') {
            throw new InvalidArgumentException(sprintf(
                'The %s does not exist or changed; reload and retry.',
                $resource,
            ));
        }
    }

    /**
     * Refuse a page window that is unbounded or malformed.
     *
     * Every paged read interpolates its window into the statement instead of binding it, so this is the
     * one place the values are constrained; the 500-row ceiling is what stops a single call from reading
     * the whole table.
     *
     * @param   int  $limit   Maximum rows requested, which must fall between 1 and 500.
     * @param   int  $offset  Rows to skip, which must not be negative.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the limit is outside 1 to 500 or the offset is negative.
     *
     * @since   2.0.0
     */
    private function assertPage(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 500 || $offset < 0) {
            throw new InvalidArgumentException('The access-control page is invalid.');
        }
    }

    /**
     * Advance one user's security epoch so credentials issued under the old authority stop verifying.
     *
     * Called by every change that alters what a user may do. The update must touch exactly one row: a
     * change made against a user who is no longer there fails here rather than quietly losing the
     * invalidation that the change depended on.
     *
     * @param   string  $userId  UUID of the user whose epoch is advanced.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no user row carries that identifier.
     *
     * @since   2.0.0
     */
    private function incrementSecurityEpoch(string $userId): void
    {
        $this->assertChanged($this->database->executeStatement(sprintf(
            'UPDATE %s SET security_epoch = security_epoch + 1 WHERE id = ?',
            $this->tables->quoted('users'),
        ), [$userId], [Types::GUID]), 'user');
    }

    /**
     * Advance the security epoch of every user attached to a role by either assignment path.
     *
     * A role carries no epoch of its own, so changing what it confers has to be pushed down to each
     * user individually. Both installation-wide assignments and organization membership assignments
     * are included without lifecycle or site filtering: an old credential must remain stale if a user,
     * membership or organization is later reactivated. `UNION` deduplicates a user attached through
     * both paths before their epoch is incremented. Membership is read once before the loop, which means
     * a user assigned the role after that read is not covered — callers hold the role lock to close that
     * window.
     *
     * @param   string  $roleId  UUID of the role whose members are invalidated.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a member's user row disappears between the membership read
     *          and its own update.
     *
     * @since   2.0.0
     */
    private function incrementRoleMembersEpoch(string $roleId): void
    {
        $users = $this->database->fetchFirstColumn(sprintf(
            'SELECT ur.user_id FROM %s ur WHERE ur.role_id = ? '
            . 'UNION SELECT m.user_id FROM %s mr INNER JOIN %s m ON m.id = mr.membership_id '
            . 'WHERE mr.role_id = ? ORDER BY user_id',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('membership_roles'),
            $this->tables->quoted('organization_memberships'),
        ), [$roleId, $roleId]);
        foreach ($users as $userId) {
            if (is_string($userId)) {
                $this->incrementSecurityEpoch($userId);
            }
        }
    }

    /**
     * Decode an optional positive integer returned by different database drivers.
     *
     * @param   mixed  $value  Nullable raw column value.
     *
     * @return  ?int  Positive integer or null.
     *
     * @throws  RuntimeException  When a non-null value is not positive integer text or an integer.
     *
     * @since   2.0.0
     */
    private function nullablePositiveInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $integer = $this->nonNegativeInteger($value);
        if ($integer < 1) {
            throw new RuntimeException('A stored token generation is invalid.');
        }

        return $integer;
    }

    /**
     * Decode a non-negative integer returned by different database drivers.
     *
     * @param   mixed  $value  Raw integer column value.
     *
     * @return  int  Non-negative integer.
     *
     * @throws  RuntimeException  When the value cannot represent a non-negative integer.
     *
     * @since   2.0.0
     */
    private function nonNegativeInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('A stored token delegation depth is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 0) {
            throw new RuntimeException('A stored token delegation depth is invalid.');
        }

        return $integer;
    }
}
