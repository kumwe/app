<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use DomainException;
use InvalidArgumentException;

/**
 * The account an authorization decision is made about, with its roles and its lifecycle state.
 *
 * This is where the identity invariants live rather than in any store: an account is keyed by a
 * canonical UUID, carries a printable display name, and holds role identifiers spelled the way
 * `CapabilityGrant` demands, deduplicated and sorted. There is no public constructor — `register()`
 * starts a fresh account pending activation with no roles, `reconstitute()` rebuilds one from a stored
 * row — so an account that breaks those rules cannot be brought into existence. Unlike most types in
 * this layer the aggregate is deliberately mutable, because `version` is the optimistic-concurrency
 * token a store writes against: every mutator advances it exactly once and only when the call really
 * changed something, so a replayed edit is never mistaken for a conflicting one. Decisions read it
 * through `canAuthenticate()` and `hasRole()`, which is all `AuthorizationService` and
 * `CapabilityGrant::appliesTo()` need from an actor.
 *
 * @since  2.0.0
 */
final class User
{
    /**
     * Role identifiers the account holds, in the canonical form `normalizeRoles()` produces.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $roles;

    /**
     * Assemble an account from parts the named constructors have already folded.
     *
     * Private so that every instance passes through `register()` or `reconstitute()`, both of which
     * lowercase the identifier and trim the name before the invariants are asserted here.
     *
     * @param   string        $id           Canonical UUID the account is identified by.
     * @param   EmailAddress  $email        Address the account signs in with.
     * @param   string        $displayName  Human-readable name, already trimmed.
     * @param   array<mixed>  $roles        Roles to hold, normalised on the way in.
     * @param   UserStatus    $status       Lifecycle state this instance starts at.
     * @param   int           $version      Optimistic-concurrency counter the instance starts at.
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical UUID, the display name is
     *          empty, over 191 characters or carries control characters, the version is negative, or the
     *          roles are not a list of valid role identifiers.
     *
     * @since   2.0.0
     */
    private function __construct(
        private readonly string $id,
        private EmailAddress $email,
        private string $displayName,
        array $roles,
        private UserStatus $status,
        private int $version,
    ) {
        self::assertId($id);
        self::assertDisplayName($displayName);

        if ($version < 0) {
            throw new InvalidArgumentException('A user version cannot be negative.');
        }

        $this->roles = self::normalizeRoles($roles);
    }

    /**
     * Start a brand-new account, pending activation and holding nothing.
     *
     * Registration confers no authority on purpose: the account cannot sign in until `activate()` is
     * called, and every role has to be assigned explicitly afterwards.
     *
     * @param   string        $id           Canonical UUID for the new account; lowercased here.
     * @param   EmailAddress  $email        Address the account will sign in with.
     * @param   string        $displayName  Human-readable name; surrounding whitespace is trimmed here.
     *
     * @return  self  A `UserStatus::Pending` account with no roles, at version 0.
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical UUID, or the trimmed
     *          display name is empty, over 191 characters or carries control characters.
     *
     * @since   2.0.0
     */
    public static function register(string $id, EmailAddress $email, string $displayName): self
    {
        return new self(strtolower($id), $email, trim($displayName), [], UserStatus::Pending, 0);
    }

    /**
     * Rebuild an account from the parts a store handed back.
     *
     * The same invariants apply as on registration, so a row that has drifted — a duplicate role, an
     * identifier that is no longer a UUID — is refused at the boundary instead of being carried into a
     * decision. Roles are deduplicated and sorted on the way in, so the rebuilt list can be shorter and
     * differently ordered than the stored one.
     *
     * @param   string        $id           Canonical UUID the account was stored under; lowercased here.
     * @param   EmailAddress  $email        Address as stored.
     * @param   string        $displayName  Human-readable name as stored; trimmed here.
     * @param   array<mixed>  $roles        Roles as stored, in any order and possibly with duplicates.
     * @param   UserStatus    $status       Lifecycle state as stored.
     * @param   int           $version      Version the row was read at, which later writes are checked against.
     *
     * @return  self  The account exactly as stored, minus role duplication and ordering.
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical UUID, the display name is
     *          empty, over 191 characters or carries control characters, the version is negative, or the
     *          stored roles are not a list of valid role identifiers.
     *
     * @since   2.0.0
     */
    public static function reconstitute(
        string $id,
        EmailAddress $email,
        string $displayName,
        array $roles,
        UserStatus $status,
        int $version,
    ): self {
        return new self(strtolower($id), $email, trim($displayName), $roles, $status, $version);
    }

    /**
     * The canonical UUID the account is stored, audited and referred to by.
     *
     * @return  string  Lowercase canonical UUID; never changes over the instance's life.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * The address the account signs in with, already normalised by `EmailAddress`.
     *
     * @return  EmailAddress
     *
     * @since   2.0.0
     */
    public function email(): EmailAddress
    {
        return $this->email;
    }

    /**
     * The human-readable name shown wherever the account is listed.
     *
     * @return  string  Trimmed, between 1 and 191 printable characters.
     *
     * @since   2.0.0
     */
    public function displayName(): string
    {
        return $this->displayName;
    }

    /**
     * The roles the account holds, from which its capability grants are derived.
     *
     * @return  list<string>  Unique identifiers in ascending string order; empty when nothing is assigned.
     *
     * @since   2.0.0
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * The lifecycle state the account is currently in.
     *
     * @return  UserStatus
     *
     * @since   2.0.0
     */
    public function status(): UserStatus
    {
        return $this->status;
    }

    /**
     * The optimistic-concurrency counter a store writes against.
     *
     * @return  int  Zero for a freshly registered account, advanced once by each mutation that changed
     *          something; a call that changed nothing leaves it where it was.
     *
     * @since   2.0.0
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Whether the account may currently sign in.
     *
     * Decided by the lifecycle state alone, so roles have no bearing on it. `AuthorizationService` asks
     * this before consulting any policy, which is what stops a permissive rule from rescuing a suspended
     * or disabled account.
     *
     * @return  bool  True only when the status is `UserStatus::Active`.
     *
     * @since   2.0.0
     */
    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }

    /**
     * Whether the account holds one named role.
     *
     * `CapabilityGrant::appliesTo()` asks this first, so a grant hanging off a role the account does not
     * hold can never contribute to a decision. The comparison is exact — no case folding, no prefix
     * matching, no role hierarchy.
     *
     * @param   string  $role  Role identifier to look for.
     *
     * @return  bool  True when the role is among the assigned roles.
     *
     * @since   2.0.0
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Replace the sign-in address, advancing the version only when the address really differs.
     *
     * @param   EmailAddress  $email  Replacement address; sameness is judged by `EmailAddress::equals()`,
     *          so a re-submitted address in different casing counts as no change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function changeEmail(EmailAddress $email): void
    {
        if ($this->email->equals($email)) {
            return;
        }

        $this->email = $email;
        ++$this->version;
    }

    /**
     * Replace the display name, advancing the version only when the trimmed name really differs.
     *
     * @param   string  $displayName  Replacement name; trimmed before it is validated and compared, so
     *          padding alone is not a change.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the trimmed name is empty, over 191 characters, or carries
     *          control characters.
     *
     * @since   2.0.0
     */
    public function rename(string $displayName): void
    {
        $displayName = trim($displayName);
        self::assertDisplayName($displayName);

        if ($this->displayName === $displayName) {
            return;
        }

        $this->displayName = $displayName;
        ++$this->version;
    }

    /**
     * Move the account into the state where it may sign in.
     *
     * Legal from pending and from suspended, and a no-op on an already-active account that leaves the
     * version alone. A disabled account is refused, which is what makes disabling a real revocation
     * rather than a reversible pause.
     *
     * @return  void
     *
     * @throws  DomainException  When the account has been disabled.
     *
     * @since   2.0.0
     */
    public function activate(): void
    {
        if (!$this->status->canTransitionTo(UserStatus::Active)) {
            throw new DomainException('A disabled user cannot be reactivated.');
        }

        $this->transitionTo(UserStatus::Active);
    }

    /**
     * Withdraw the ability to sign in while leaving the account and its roles in place.
     *
     * Only an active account can be suspended — a pending one was never usable and a disabled one is
     * already gone — and suspending an already-suspended account is a no-op.
     *
     * @return  void
     *
     * @throws  DomainException  When the account is neither active nor already suspended.
     *
     * @since   2.0.0
     */
    public function suspend(): void
    {
        if (!$this->status->canTransitionTo(UserStatus::Suspended)) {
            throw new DomainException('Only an active user can be suspended.');
        }

        $this->transitionTo(UserStatus::Suspended);
    }

    /**
     * Revoke the account for good.
     *
     * Legal from every state and never guarded, because disabling is terminal: no later call can bring
     * the account back to active or suspended. This is the move to reach for when access must not return.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function disable(): void
    {
        $this->transitionTo(UserStatus::Disabled);
    }

    /**
     * Give the account a role it does not already hold.
     *
     * Assignment is idempotent: a role already held is ignored and the version stays put. The list is
     * re-sorted after each addition, so the order roles were assigned in is not recorded anywhere.
     *
     * @param   string  $role  Role identifier to add, held to the grammar `CapabilityGrant` enforces.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the role is not a lowercase identifier of 2 to 64 characters.
     *
     * @since   2.0.0
     */
    public function assignRole(string $role): void
    {
        CapabilityGrant::assertRole($role);

        if ($this->hasRole($role)) {
            return;
        }

        $this->roles[] = $role;
        sort($this->roles, SORT_STRING);
        ++$this->version;
    }

    /**
     * Take a role away from the account.
     *
     * Revocation is idempotent: a role that is not held is ignored and the version stays put. The
     * identifier is validated first, so a malformed role is refused rather than quietly matching nothing.
     *
     * @param   string  $role  Role identifier to remove.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the role is not a lowercase identifier of 2 to 64 characters.
     *
     * @since   2.0.0
     */
    public function revokeRole(string $role): void
    {
        CapabilityGrant::assertRole($role);
        $index = array_search($role, $this->roles, true);

        if ($index === false) {
            return;
        }

        array_splice($this->roles, $index, 1);
        ++$this->version;
    }

    /**
     * Record a lifecycle move the caller has already found legal.
     *
     * The only writer of the status field, which is what keeps the version advancing exactly once per
     * real change and makes a move to the state already held cost nothing.
     *
     * @param   UserStatus  $status  State to move to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function transitionTo(UserStatus $status): void
    {
        if ($this->status === $status) {
            return;
        }

        $this->status = $status;
        ++$this->version;
    }

    /**
     * Refuse an identifier that is not a canonical UUID.
     *
     * The pattern accepts versions 1 to 8 with an RFC 4122 variant nibble, which admits the UUIDv7 values
     * the stores mint while still rejecting a hand-written slug. Matching is case-insensitive.
     *
     * @param   string  $id  Candidate identifier.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not a canonical UUID.
     *
     * @since   2.0.0
     */
    private static function assertId(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) !== 1) {
            throw new InvalidArgumentException('A user ID must be a canonical UUID.');
        }
    }

    /**
     * Refuse a display name that is empty, too long, or carries control characters.
     *
     * Length is counted in characters when `mbstring` is present and in bytes otherwise, so a multi-byte
     * name is measured by what a reader sees wherever the extension is installed. Control characters are
     * rejected because the name is rendered in operator surfaces and written into audit records.
     *
     * @param   string  $displayName  Candidate name, already trimmed by the caller.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the name is empty, longer than 191 characters, or contains a
     *          C0 control character or DEL.
     *
     * @since   2.0.0
     */
    private static function assertDisplayName(string $displayName): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($displayName) : strlen($displayName);

        if ($displayName === '' || $length > 191 || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1) {
            throw new InvalidArgumentException('A display name must contain between 1 and 191 printable characters.');
        }
    }

    /**
     * Validate an arbitrary array of roles and reduce it to the canonical list the aggregate holds.
     *
     * Both halves matter. Every entry is held to `CapabilityGrant::assertRole()`, so a role that could
     * never appear on a grant can never be assigned either; the survivors are then deduplicated and
     * sorted, so two accounts holding the same roles carry identical lists whatever order they arrived in.
     *
     * @param   array<mixed>  $roles  Roles as supplied, in any order and possibly with duplicates.
     *
     * @return  list<string>  Unique role identifiers in ascending string order.
     *
     * @throws  InvalidArgumentException  When the array is not a list, an entry is not a string, or an
     *          entry is not a lowercase identifier of 2 to 64 characters.
     *
     * @since   2.0.0
     */
    private static function normalizeRoles(array $roles): array
    {
        if (!array_is_list($roles)) {
            throw new InvalidArgumentException('User roles must be a list.');
        }

        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new InvalidArgumentException('Every user role must be a string.');
            }

            CapabilityGrant::assertRole($role);
        }

        /** @var list<string> $roles */
        $roles = array_values(array_unique($roles));
        sort($roles, SORT_STRING);

        return $roles;
    }
}
