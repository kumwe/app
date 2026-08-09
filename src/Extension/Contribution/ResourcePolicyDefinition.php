<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationDefinitionLifecycle;
use Kumwe\CMS\Application\Authorization\ResourcePolicyTarget;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Declarative contribution binding one owned capability to bounded resource selectors.
 *
 * The contribution is data only: exact resource selectors, installation scope, lifecycle, and version.
 * Core may additionally name purpose-built system identities; the operational registry rejects any
 * extension definition that attempts the same. Conditional attribute policies are a separate bounded
 * layer and cannot enter through this base action/resource binding.
 *
 * @since  2.0.0
 */
final readonly class ResourcePolicyDefinition implements ContributionDefinition
{
    /**
     * Normalized owner-namespaced policy identifier.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $id;

    /**
     * Normalized capability identifier this policy binds.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $capability;

    /**
     * Deterministically ordered resource selectors.
     *
     * @var    list<ResourcePolicyTarget>
     * @since  2.0.0
     */
    public array $resources;

    /**
     * Typed system identities permitted by this binding; extensions must leave the list empty.
     *
     * @var    list<SystemIdentity>
     * @since  2.0.0
     */
    public array $systemIdentities;

    /**
     * Validate one contributed resource policy before ownership and capability references are resolved.
     *
     * @param   string                            $id                  Policy identifier under the owner namespace.
     * @param   string                            $capability          Capability identifier this policy binds.
     * @param   iterable<ResourcePolicyTarget>    $resources           Non-empty bounded selectors.
     * @param   bool                              $installationGlobal  Whether matching resources are installation-wide.
     * @param   iterable<SystemIdentity>          $systemIdentities    Core unattended identities allowed to use it.
     * @param   AuthorizationDefinitionLifecycle  $lifecycle           Current runtime lifecycle state.
     * @param   int                               $version             Positive owner-controlled definition version.
     *
     * @throws  InvalidArgumentException  When an identifier, selector set, or version is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        string $id,
        string $capability,
        iterable $resources,
        public bool $installationGlobal = false,
        iterable $systemIdentities = [],
        public AuthorizationDefinitionLifecycle $lifecycle = AuthorizationDefinitionLifecycle::Active,
        public int $version = 1,
    ) {
        $this->id = Capability::fromString($id)->value();
        $this->capability = Capability::fromString($capability)->value();
        if ($version < 1) {
            throw new InvalidArgumentException('A contributed resource-policy version must be positive.');
        }

        $targets = [];
        foreach ($resources as $resource) {
            $targets[json_encode($resource->toArray(), JSON_THROW_ON_ERROR)] = $resource;
        }
        if ($targets === [] || count($targets) > 64) {
            throw new InvalidArgumentException('A contributed resource policy must declare between 1 and 64 targets.');
        }
        ksort($targets, SORT_STRING);
        $this->resources = array_values($targets);

        $systems = [];
        foreach ($systemIdentities as $identity) {
            $systems[$identity->value] = $identity;
        }
        ksort($systems, SORT_STRING);
        $this->systemIdentities = array_values($systems);
    }

    /**
     * The identifier the registrar indexes this resource policy under.
     *
     * @return  string  Normalized owner-namespaced policy identifier.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->id;
    }

    /**
     * Export the stable declaration shape compared with the signed manifest and shown in inventory.
     *
     * @return  array{
     *              id: string,
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
            'capability' => $this->capability,
            'resources' => array_map(
                static fn (ResourcePolicyTarget $target): array => $target->toArray(),
                $this->resources,
            ),
            'installation_global' => $this->installationGlobal,
            'system_identities' => array_map(
                static fn (SystemIdentity $identity): string => $identity->value,
                $this->systemIdentities,
            ),
            'lifecycle' => $this->lifecycle->value,
            'version' => $this->version,
        ];
    }
}
