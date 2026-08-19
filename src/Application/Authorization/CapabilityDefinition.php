<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use InvalidArgumentException;
use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;

/**
 * Operational metadata for one capability recognised by the authorization gateway.
 *
 * The definition makes ownership and delegation constraints data rather than branches in the
 * gateway. An empty allowed-scope list marks a capability as system-only; otherwise a human grant
 * may exercise it and it may be delegated only when both `delegatable` and the requested scope type
 * permit it. Lifecycle and version travel with the definition so stale extension grants fail closed
 * when their owner disables or retires the capability.
 *
 * @since  2.0.0
 */
final readonly class CapabilityDefinition
{
    /**
     * Scope types at which this capability may be granted, sorted and de-duplicated.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $allowedScopes;

    /**
     * Validate and hold one owner-bound capability definition.
     *
     * @param   Capability                        $capability         Permission code the definition describes.
     * @param   string                            $owner              `core` or the owning extension's
     *          `vendor/name` identifier.
     * @param   iterable<string>                  $allowedScopes      Grant-scope types accepted for this
     *          capability; an empty set makes it system-only.
     * @param   bool                              $delegatable        Whether a human may grant the capability
     *          onward at an allowed scope.
     * @param   bool                              $highImpact         Whether exercising it requires the
     *          high-impact controls layered above the base grant check.
     * @param   AuthorizationDefinitionLifecycle  $lifecycle          Current enforceability state.
     * @param   int                               $definitionVersion  Positive owner-controlled definition version.
     *
     * @throws  InvalidArgumentException  When the owner, namespace, scope list, or version is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public Capability $capability,
        public string $owner,
        iterable $allowedScopes,
        public bool $delegatable,
        public bool $highImpact,
        public AuthorizationDefinitionLifecycle $lifecycle,
        public int $definitionVersion,
    ) {
        self::assertOwner($owner);
        self::assertOwnedIdentifier($owner, $capability->value(), 'capability');
        if ($definitionVersion < 1) {
            throw new InvalidArgumentException('A capability definition version must be positive.');
        }

        $scopes = [];
        foreach ($allowedScopes as $scope) {
            if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $scope) !== 1) {
                throw new InvalidArgumentException('A capability allowed scope must be a lowercase identifier.');
            }
            $scopes[$scope] = true;
        }
        if (count($scopes) > 64) {
            throw new InvalidArgumentException('A capability may declare at most 64 allowed scope types.');
        }
        ksort($scopes, SORT_STRING);
        $this->allowedScopes = array_keys($scopes);
    }

    /**
     * Whether this definition may currently take part in an authorization decision.
     *
     * @return  bool  True while the lifecycle is active or deprecated.
     *
     * @since   2.0.0
     */
    public function enforceable(): bool
    {
        return $this->lifecycle->enforceable();
    }

    /**
     * Whether a human grant can ever exercise this capability.
     *
     * @return  bool  False for system-only capabilities whose allowed-scope list is empty.
     *
     * @since   2.0.0
     */
    public function allowsHumanGrant(): bool
    {
        return $this->allowedScopes !== [];
    }

    /**
     * Whether this capability may be delegated at the requested scope.
     *
     * @param   GrantScope  $scope  Exact grant reach proposed by the caller.
     *
     * @return  bool  True only for an enforceable, delegatable definition listing the scope type.
     *
     * @since   2.0.0
     */
    public function allowsDelegation(GrantScope $scope): bool
    {
        return $this->enforceable()
            && $this->delegatable
            && in_array($scope->type(), $this->allowedScopes, true);
    }

    /**
     * Export the stable metadata shape used by diagnostics and persistence adapters.
     *
     * @return  array{
     *              id: string,
     *              owner: string,
     *              allowed_scopes: list<string>,
     *              delegatable: bool,
     *              high_impact: bool,
     *              lifecycle: string,
     *              version: int
     *          }
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->capability->value(),
            'owner' => $this->owner,
            'allowed_scopes' => $this->allowedScopes,
            'delegatable' => $this->delegatable,
            'high_impact' => $this->highImpact,
            'lifecycle' => $this->lifecycle->value,
            'version' => $this->definitionVersion,
        ];
    }

    /**
     * Validate the string identity shared by capability and resource-policy owners.
     *
     * @param   string  $owner  Candidate `core` or `vendor/name` owner identifier.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is neither core nor an extension name.
     *
     * @since   2.0.0
     */
    public static function assertOwner(string $owner): void
    {
        if (
            $owner !== 'core'
            && preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,62})\/[a-z0-9](?:[a-z0-9._-]{0,62})$/D', $owner) !== 1
        ) {
            throw new InvalidArgumentException('An authorization definition owner must be core or vendor/name.');
        }
    }

    /**
     * Refuse an extension-owned identifier outside the extension's dotted namespace.
     *
     * Core capability identifiers retain their historical vocabulary and therefore need no `core.`
     * prefix. Every resource-policy identifier, including core's, is namespace checked by the outer
     * contribution owner before reaching this lower-level definition.
     *
     * @param   string  $owner       Validated definition owner.
     * @param   string  $identifier  Capability or policy identifier being claimed.
     * @param   string  $kind        Kind named in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an extension claims another owner's identifier.
     *
     * @since   2.0.0
     */
    public static function assertOwnedIdentifier(string $owner, string $identifier, string $kind): void
    {
        if ($owner === 'core') {
            return;
        }

        $namespace = str_replace('/', '.', $owner) . '.';
        if (!str_starts_with($identifier, $namespace)) {
            throw new InvalidArgumentException(sprintf(
                'Authorization owner %s cannot claim %s identifier %s.',
                $owner,
                $kind,
                $identifier,
            ));
        }
    }
}
