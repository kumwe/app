<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use DateTimeImmutable;

/**
 * Persistence contract behind every user, role, capability grant and API token an operator edits.
 *
 * `AccessControlService` is written against this interface alone, which keeps the identity rules —
 * who may see a record, which lifecycle moves are legal, what may be delegated — in one auditable
 * place rather than spread through SQL. The members fall into three kinds: paged readers whose
 * results the service filters row by row, pessimistic locks a caller takes before it decides
 * anything, and writes that must fail loudly instead of quietly affecting no rows. Two obligations
 * shape every implementation: a write that names a stale version or a vanished row is rejected rather
 * than absorbed, and a change to what a user may do advances that user's security epoch, so bearer
 * tokens minted under the old authority stop verifying instead of outliving it.
 *
 * @since  2.0.0
 */
interface AccessControlRepository
{
    /**
     * Read one page of users, each with the roles assigned to it.
     *
     * Rows come back unfiltered: the caller pages through them precisely so it can judge each one's
     * visibility itself rather than trusting the store to have done it.
     *
     * @param   int  $limit   Maximum rows to read in this page.
     * @param   int  $offset  Rows to skip before collecting the page.
     *
     * @return  list<array<string, mixed>>  One row per user, each carrying its assigned roles.
     *
     * @since   2.0.0
     */
    public function users(int $limit = 100, int $offset = 0): array;

    /**
     * Read one page of roles, each with the capability grants attached to it.
     *
     * @param   int  $limit   Maximum rows to read in this page.
     * @param   int  $offset  Rows to skip before collecting the page.
     *
     * @return  list<array<string, mixed>>  One row per role, each carrying its capability grants.
     *
     * @since   2.0.0
     */
    public function roles(int $limit = 100, int $offset = 0): array;

    /**
     * Read one page of the capability vocabulary a grant may name.
     *
     * This is the fixed catalogue the migrations install, not what any role holds; the administrator
     * offers it as the choice list when someone writes a grant.
     *
     * @param   int  $limit   Maximum rows to read in this page.
     * @param   int  $offset  Rows to skip before collecting the page.
     *
     * @return  list<array{code: string, description: string}>  Capability codes with their operator-facing text.
     *
     * @since   2.0.0
     */
    public function capabilities(int $limit = 100, int $offset = 0): array;

    /**
     * Read one page of the API tokens issued for a single site.
     *
     * Only metadata comes back — the secret was shown once at issuance and only its digest is kept — so
     * a row here is safe to render in the administrator.
     *
     * @param   string  $siteIdentifier  Site whose tokens are listed; another site's tokens never appear.
     * @param   int     $limit           Maximum rows to read in this page.
     * @param   int     $offset          Rows to skip before collecting the page.
     *
     * @return  list<array<string, mixed>>  Newest first, each row carrying the token's subject, scope and life.
     *
     * @since   2.0.0
     */
    public function tokens(string $siteIdentifier, int $limit = 100, int $offset = 0): array;

    /**
     * Read the lifecycle status stored for one user.
     *
     * @param   string  $userId  UUID of the user whose status decides the transition being attempted.
     *
     * @return  ?string  The stored lifecycle status, read under the caller's user lock, or null when no such
     *          user exists.
     *
     * @since   2.0.0
     */
    public function userStatus(string $userId): ?string;

    /**
     * Write a new user together with the password credential it signs in with.
     *
     * @param   string             $id            UUID to store the user under.
     * @param   string             $email         Address the user signs in with, already normalised.
     * @param   string             $displayName   Human-readable name shown wherever the user is listed.
     * @param   string             $status        Lifecycle status to start at, as a `UserStatus` value.
     * @param   string             $passwordHash  Already-hashed password; plaintext never reaches this port.
     * @param   DateTimeImmutable  $at            Instant recorded as the creation and last-change time.
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
    ): void;

    /**
     * Apply an edited user record, refusing the write when someone else has moved it on.
     *
     * The expected version is what makes the edit optimistic: the row changes only while it still
     * carries the version the caller read. A successful write also advances the user's security epoch,
     * so tokens issued before the change stop verifying.
     *
     * @param   string             $id               UUID of the user being edited.
     * @param   string             $email            Replacement address, already normalised.
     * @param   string             $displayName      Replacement human-readable name.
     * @param   string             $status           Replacement lifecycle status, as a `UserStatus` value.
     * @param   int                $expectedVersion  Version the caller read; the write applies only at it.
     * @param   DateTimeImmutable  $at               Instant recorded as the last-change time.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When no row matched, meaning the user vanished or was edited
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
    ): void;

    /**
     * Write a new role for capability grants to hang from.
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
    public function insertRole(string $id, string $code, string $name, DateTimeImmutable $at): void;

    /**
     * Give a user a role unless they already hold it.
     *
     * Assignment is idempotent, so a replay changes nothing; the assignment that does land advances the
     * user's security epoch, because it widens what their existing tokens would otherwise be entitled to.
     *
     * @param   string             $userId   UUID of the user gaining the role.
     * @param   string             $roleId   UUID of the role being assigned.
     * @param   string             $actorId  UUID of the administrator assigning it, kept for the audit trail.
     * @param   DateTimeImmutable  $at       Instant recorded as the assignment time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assignRole(string $userId, string $roleId, string $actorId, DateTimeImmutable $at): void;

    /**
     * Take a role away from a user.
     *
     * The removal advances the user's security epoch, so tokens minted while they still held the role
     * stop verifying rather than outliving the revocation.
     *
     * @param   string  $userId  UUID of the user losing the role.
     * @param   string  $roleId  UUID of the role being taken away.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the user did not hold that role, so nothing was removed.
     *
     * @since   2.0.0
     */
    public function revokeRole(string $userId, string $roleId): void;

    /**
     * Attach a capability to a role, globally or within one named scope.
     *
     * Authority is the caller's business: this port writes a grant that a delegation check has already
     * approved. Every current member of the role has their security epoch advanced, so tokens they
     * already hold cannot silently pick the new capability up.
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
    ): void;

    /**
     * Remove a capability grant from the role that holds it.
     *
     * Members of the role have their security epoch advanced, so a token that already carried the
     * capability loses it instead of keeping it until expiry.
     *
     * @param   string  $grantId  UUID of the grant to remove.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When no grant carries that identifier, so nothing was removed.
     *
     * @since   2.0.0
     */
    public function revokeGrant(string $grantId): void;

    /**
     * Mark one live API token as revoked.
     *
     * Revocation is recorded rather than deleted, so the token's history and its reason survive for
     * audit; presenting the secret afterwards no longer authenticates.
     *
     * @param   string             $tokenId  UUID of the token to revoke.
     * @param   DateTimeImmutable  $at       Instant recorded as the revocation time.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the token does not exist or was already revoked.
     *
     * @since   2.0.0
     */
    public function revokeToken(string $tokenId, DateTimeImmutable $at): void;

    /**
     * Resolve a normalised email address to the user allowed to sign in with it.
     *
     * Only an account that may currently authenticate answers, which is what stops a token being issued
     * for a subject that has been suspended or disabled.
     *
     * @param   string  $normalizedEmail  Address already put through `EmailAddress` normalisation.
     *
     * @return  ?string  UUID of the active user, or null when no active account holds that address.
     *
     * @since   2.0.0
     */
    public function userIdByEmail(string $normalizedEmail): ?string;

    /**
     * Read which site a token belongs to.
     *
     * Callers compare the answer against their own site first, so a token cannot be revoked or rotated
     * from a site that does not own it.
     *
     * @param   string  $tokenId  UUID of the token being located.
     *
     * @return  ?string  Site identifier the token is confined to, or null when no such token exists.
     *
     * @since   2.0.0
     */
    public function tokenSite(string $tokenId): ?string;

    /**
     * Read everything a rotation must copy from a token that is still usable.
     *
     * Usable is stricter than stored: the token must be unrevoked, unexpired, still on its subject's
     * current security epoch, and owned by an account that may sign in. Rotation asks for the lock from
     * inside its transaction so the record cannot be revoked between this read and the replacement.
     *
     * @param   string  $tokenId  UUID of the token about to be rotated.
     * @param   bool    $lock     Whether to hold the row for the rest of the caller's transaction.
     *
     * @return  array{subject_id: string, email: string, capabilities: list<string>, site_identifier: string,
     *          audience: string, purpose: string}|null  Null when the token is absent or no longer usable.
     *
     * @since   2.0.0
     */
    public function activeTokenForRotation(string $tokenId, bool $lock = false): ?array;

    /**
     * Revoke every live token a subject holds, in every site, as a break-glass measure.
     *
     * Implementations also advance the subject's security epoch, so a credential that somehow escaped
     * the update still fails its epoch check on the next request.
     *
     * @param   string             $userId  UUID of the subject whose tokens are all being destroyed.
     * @param   DateTimeImmutable  $at      Instant recorded as the revocation time.
     * @param   string             $reason  Operator-supplied justification stored beside each token.
     *
     * @return  int  How many live tokens were revoked, zero when the subject held none.
     *
     * @since   2.0.0
     */
    public function revokeSubjectTokens(string $userId, DateTimeImmutable $at, string $reason): int;

    /**
     * Revoke the live tokens a subject holds for one site, leaving their other sites alone.
     *
     * The contained counterpart of `revokeSubjectTokens()`: it does not move the security epoch, so the
     * subject's credentials elsewhere keep working.
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
    ): int;

    /**
     * Hold the user's row for the rest of the caller's transaction.
     *
     * Callers lock before they read a status or count tokens, so a concurrent edit cannot land between
     * the decision and the write that rests on it.
     *
     * @param   string  $userId  UUID of the user to lock.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the user does not exist, so nothing could be locked.
     *
     * @since   2.0.0
     */
    public function lockUser(string $userId): void;

    /**
     * Hold the role's row for the rest of the caller's transaction.
     *
     * Role assignment and granting lock first, so the delegation check cannot be made against grants
     * that another administrator changes before the write lands.
     *
     * @param   string  $roleId  UUID of the role to lock.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the role does not exist, so nothing could be locked.
     *
     * @since   2.0.0
     */
    public function lockRole(string $roleId): void;

    /**
     * Read the capabilities one role confers, with the scope each applies in.
     *
     * Delegation is checked against this list: an actor may only assign a role whose every grant they
     * could have written themselves.
     *
     * @param   string  $roleId  UUID of the role being inspected.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>  Empty when the
     *          role confers nothing.
     *
     * @since   2.0.0
     */
    public function roleGrants(string $roleId): array;

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
    public function roleCode(string $roleId): ?string;

    /**
     * Read every capability a user holds through their roles, with the scope each applies in.
     *
     * The deduplicated union across all of the user's roles. Token issuance checks each requested
     * capability against this, so a token can never carry more than its subject already has.
     *
     * @param   string  $userId  UUID of the user being inspected.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>  Empty when the
     *          user holds nothing.
     *
     * @since   2.0.0
     */
    public function userGrants(string $userId): array;

    /**
     * Read one capability grant by its identifier.
     *
     * Revocation reads the grant first so it can authorize against the role that holds it, rather than
     * against the grant alone.
     *
     * @param   string  $grantId  UUID of the grant being read.
     *
     * @return  array{role_id: string, capability: string, scope_type: string, scope_identifier: ?string}|null
     *          Null when no grant carries that identifier.
     *
     * @since   2.0.0
     */
    public function grantRecord(string $grantId): ?array;
}
