<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use InvalidArgumentException;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * Owner-bound action/resource binding used by the canonical authorization gateway.
 *
 * A definition states which bounded resource selectors one capability may reach, whether those
 * resources belong to the whole installation, and which purpose-built system identities may use the
 * binding without a human grant. It deliberately carries no callback, SQL, or expression string;
 * attribute policies can layer on this base binding without turning registry loading into code execution.
 *
 * @since  2.0.0
 */
final readonly class ResourcePolicyDefinition
{
    /**
     * Normalized dotted identifier uniquely naming this policy definition.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $id;

    /**
     * Resource selectors this policy covers, sorted into a deterministic order.
     *
     * @var    list<ResourcePolicyTarget>
     * @since  2.0.0
     */
    public array $targets;

    /**
     * Purpose-built unattended identities permitted to exercise this exact binding.
     *
     * @var    list<SystemIdentity>
     * @since  2.0.0
     */
    public array $systemIdentities;

    /**
     * Validate and hold one resource-policy definition.
     *
     * @param   string                            $id                  Dotted policy identifier under its owner.
     * @param   string                            $owner               `core` or the owning extension's
     *          `vendor/name` identifier.
     * @param   Capability                        $capability          Action whose reach this policy defines.
     * @param   iterable<ResourcePolicyTarget>    $targets             Non-empty bounded resource selectors.
     * @param   bool                              $installationGlobal  Whether matching resources require a
     *          global human grant rather than site ownership.
     * @param   iterable<SystemIdentity>          $systemIdentities    Core system identities permitted to use it.
     * @param   AuthorizationDefinitionLifecycle  $lifecycle           Current enforceability state.
     * @param   int                               $definitionVersion   Positive owner-controlled definition version.
     *
     * @throws  InvalidArgumentException  When the identifier, owner, targets, system identities, or version is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        string $id,
        public string $owner,
        public Capability $capability,
        iterable $targets,
        public bool $installationGlobal,
        iterable $systemIdentities,
        public AuthorizationDefinitionLifecycle $lifecycle,
        public int $definitionVersion,
    ) {
        CapabilityDefinition::assertOwner($owner);
        $this->id = Capability::fromString($id)->value();
        if ($owner === 'core' && !str_starts_with($this->id, 'core.')) {
            throw new InvalidArgumentException('Core resource-policy identifiers must use the core namespace.');
        }
        CapabilityDefinition::assertOwnedIdentifier($owner, $this->id, 'resource policy');
        if ($definitionVersion < 1) {
            throw new InvalidArgumentException('A resource-policy definition version must be positive.');
        }

        $indexed = [];
        foreach ($targets as $declaredTarget) {
            $signature = json_encode($declaredTarget->toArray(), JSON_THROW_ON_ERROR);
            $indexed[$signature] = $declaredTarget;
        }
        if ($indexed === [] || count($indexed) > 64) {
            throw new InvalidArgumentException('A resource policy must declare between 1 and 64 targets.');
        }
        ksort($indexed, SORT_STRING);
        $normalized = array_values($indexed);
        foreach ($normalized as $index => $normalizedTarget) {
            foreach (array_slice($normalized, $index + 1) as $other) {
                if ($normalizedTarget->overlaps($other)) {
                    throw new InvalidArgumentException('A resource policy cannot contain overlapping targets.');
                }
            }
        }
        $this->targets = $normalized;

        $systems = [];
        foreach ($systemIdentities as $identity) {
            $systems[$identity->value] = $identity;
        }
        if ($owner !== 'core' && $systems !== []) {
            throw new InvalidArgumentException(
                'Extension resource policies cannot grant authority to system identities.',
            );
        }
        ksort($systems, SORT_STRING);
        $this->systemIdentities = array_values($systems);
    }

    /**
     * Whether this definition may currently take part in a decision.
     *
     * @return  bool  True while its lifecycle remains active or deprecated.
     *
     * @since   2.0.0
     */
    public function enforceable(): bool
    {
        return $this->lifecycle->enforceable();
    }

    /**
     * Whether this policy binds its capability to the requested resource.
     *
     * @param   AuthorizationResource  $resource  Target being evaluated.
     *
     * @return  bool  True when an enforceable target selector covers the resource.
     *
     * @since   2.0.0
     */
    public function matches(AuthorizationResource $resource): bool
    {
        if (!$this->enforceable()) {
            return false;
        }

        foreach ($this->targets as $target) {
            if ($target->matches($resource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this exact binding grants authority to an unattended identity.
     *
     * @param   SystemIdentity  $identity  Purpose-built identity carried by the execution context.
     *
     * @return  bool  True when the policy's typed allowlist contains the identity.
     *
     * @since   2.0.0
     */
    public function allowsSystemIdentity(SystemIdentity $identity): bool
    {
        foreach ($this->systemIdentities as $allowed) {
            if ($allowed === $identity) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this definition overlaps another binding for the same capability.
     *
     * @param   self  $other  Definition being considered for registration.
     *
     * @return  bool  True when both could settle the same action/resource request.
     *
     * @since   2.0.0
     */
    public function overlaps(self $other): bool
    {
        if (!$this->capability->equals($other->capability)) {
            return false;
        }
        foreach ($this->targets as $target) {
            foreach ($other->targets as $candidate) {
                if ($target->overlaps($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Export the stable metadata shape used by contribution reconciliation and diagnostics.
     *
     * @return  array{
     *              id: string,
     *              owner: string,
     *              capability: string,
     *              resources: list<array{type: string, identifiers: list<string>}>,
     *              installation_global: bool,
     *              system_identities: list<string>,
     *              lifecycle: string,
     *              version: int
     *          }
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->owner,
            'capability' => $this->capability->value(),
            'resources' => array_map(
                static fn (ResourcePolicyTarget $target): array => $target->toArray(),
                $this->targets,
            ),
            'installation_global' => $this->installationGlobal,
            'system_identities' => array_map(
                static fn (SystemIdentity $identity): string => $identity->value,
                $this->systemIdentities,
            ),
            'lifecycle' => $this->lifecycle->value,
            'version' => $this->definitionVersion,
        ];
    }
}
