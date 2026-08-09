<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use InvalidArgumentException;

/**
 * The reach of a grant: the whole installation, or one named resource within it.
 *
 * A capability says what may be done; this says where. A scope is either global, which covers every
 * request, or a type-and-identifier pair such as `site`/`primary`, which covers only a request naming
 * that exact resource. `covers()` is deliberately asymmetric — the scope authority was granted at is
 * asked whether it reaches the scope a request is made against — and that one-way test is what keeps a
 * grant over a single site from being read as authority over the installation. `CapabilityGrant` and
 * `PrincipalGrant` each carry one, and both states are reachable only through `global()` and
 * `named()`, so an unvalidated pair cannot exist.
 *
 * @since  2.0.0
 */
final readonly class GrantScope
{
    /**
     * Scope type reserved for the unrestricted reach, which `named()` refuses to build.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GLOBAL_TYPE = 'global';
    /**
     * Longest scope identifier accepted, matching the width of the stored scope identifier column.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_LENGTH = 191;

    /**
     * Hold a validated type and identifier pair.
     *
     * Private so that every scope comes from `global()` or `named()`, which keeps the two states
     * distinguishable: a null identifier belongs to the global scope and to nothing else.
     *
     * @param  string   $type        Resource type, or `global` for the unrestricted scope.
     * @param  ?string  $identifier  Identifier of the named resource, or null for the global scope.
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $type,
        private ?string $identifier,
    ) {
    }

    /**
     * The unrestricted scope, which covers every request whatever its type or identifier.
     *
     * @return  self  The global scope, whose `identifier()` is null.
     *
     * @since   2.0.0
     */
    public static function global(): self
    {
        return new self(self::GLOBAL_TYPE, null);
    }

    /**
     * Build a scope restricted to one identified resource.
     *
     * The type is trimmed and lowercased and the identifier trimmed before either is judged, so values
     * read back from configuration or a stored row need no cleaning first. `global` is refused as a
     * type here because the unrestricted scope carries no identifier and must come from `global()`.
     *
     * @param   string  $type        Kind of resource the grant is limited to, such as `site`.
     * @param   string  $identifier  Identifier of that resource, as the store spells it.
     *
     * @return  self  A scope covering that one resource.
     *
     * @throws  InvalidArgumentException  When the type is `global`, when the type is not a lowercase
     *          identifier of 1 to 63 characters, or when the identifier is empty, longer than 191
     *          characters, or carries control characters.
     *
     * @since   2.0.0
     */
    public static function named(string $type, string $identifier): self
    {
        $type = strtolower(trim($type));
        $identifier = trim($identifier);

        if ($type === self::GLOBAL_TYPE) {
            throw new InvalidArgumentException('The global grant scope cannot have an identifier.');
        }

        if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $type) !== 1) {
            throw new InvalidArgumentException('A grant scope type must be a valid lowercase identifier.');
        }

        if ($identifier === '' || strlen($identifier) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A grant scope identifier must contain between 1 and 191 characters.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            throw new InvalidArgumentException('A grant scope identifier cannot contain control characters.');
        }

        return new self($type, $identifier);
    }

    /**
     * The kind of resource this scope is limited to.
     *
     * @return  string  A resource type such as `site`, or `global` for the unrestricted scope.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * The resource this scope is limited to, when it is limited to one.
     *
     * @return  ?string  Identifier of the named resource; null means unrestricted, not unknown.
     *
     * @since   2.0.0
     */
    public function identifier(): ?string
    {
        return $this->identifier;
    }

    /**
     * Whether this scope reaches the whole installation rather than a single resource.
     *
     * @return  bool  True for the scope `global()` builds.
     *
     * @since   2.0.0
     */
    public function isGlobal(): bool
    {
        return $this->type === self::GLOBAL_TYPE;
    }

    /**
     * Whether a grant held at this scope reaches a request made at another.
     *
     * The receiver is the scope authority was granted at and the argument is the scope being asked for,
     * so the test runs one way: the global scope covers everything, and a named scope covers only an
     * identical type and identifier — never a different resource, and never the installation at large.
     *
     * @param   self  $requested  Scope the request is being made against.
     *
     * @return  bool  True when the grant's reach includes the requested scope.
     *
     * @since   2.0.0
     */
    public function covers(self $requested): bool
    {
        return $this->isGlobal()
            || ($this->type === $requested->type && $this->identifier === $requested->identifier);
    }

    /**
     * Whether two scopes are the same scope.
     *
     * Distinct from `covers()` in being symmetric: the global scope equals only the global scope, even
     * though it covers every other one.
     *
     * @param   self  $other  Scope to compare against.
     *
     * @return  bool  True when both the type and the identifier match.
     *
     * @since   2.0.0
     */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->identifier === $other->identifier;
    }
}
