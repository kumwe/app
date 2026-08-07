<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

final class OwnedExtensionContributionRegistrar implements ExtensionContributionRegistrar
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $expected;

    /** @var array<string, array<string, true>> */
    private array $seen = [];

    private bool $closed = false;

    public function __construct(
        private readonly ContributionOwner $owner,
        ManifestContributionSet $declared,
        private readonly ExtensionContributionRegistrySet $registries,
        private readonly bool $strict,
    ) {
        $this->expected = [
            'capability' => $this->index($declared->capabilities()),
            'workspace' => $this->index($declared->workspaces()),
            'navigation' => $this->index($declared->navigation()),
            'view' => $this->index($declared->views()),
            'route' => $this->index($declared->routes()),
        ];
    }

    public function capability(CapabilityDefinition $definition): void
    {
        $this->accept('capability', $definition->id, $definition->toArray());
        $this->registries->capabilities()->register($this->owner, $definition);
    }

    public function administratorWorkspace(AdministratorWorkspaceDefinition $definition): void
    {
        $this->accept('workspace', $definition->id, $definition->toArray());
        $this->registries->workspaces()->register($this->owner, $definition);
    }

    public function administratorNavigation(AdministratorNavigationDefinition $definition): void
    {
        $this->accept('navigation', $definition->id, $definition->toArray());
        $this->registries->navigation()->registerOwned($this->owner, $definition);
    }

    public function administratorView(AdministratorViewDefinition $definition): void
    {
        $this->accept('view', $definition->name, $definition->toArray());
        $this->registries->views()->register($this->owner, $definition);
    }

    public function administratorRoute(
        AdministratorRouteDefinition $definition,
        AdministratorRouteHandlerFactory $factory,
    ): void {
        $this->accept('route', $definition->name, $definition->toArray());
        $this->registries->routes()->register($this->owner, $definition, $factory);
    }

    public function complete(): void
    {
        $this->assertOpen();
        if ($this->strict) {
            foreach ($this->expected as $kind => $items) {
                foreach (array_keys($items) as $identifier) {
                    if (!isset($this->seen[$kind][$identifier])) {
                        throw new InvalidArgumentException(sprintf(
                            'Provider omitted declared %s contribution %s.',
                            $kind,
                            $identifier,
                        ));
                    }
                }
            }
        }
        $this->closed = true;
    }

    /** @param array<string, mixed> $actual */
    private function accept(string $kind, string $identifier, array $actual): void
    {
        $this->assertOpen();
        $this->owner->assertOwns($identifier, $kind);
        if (isset($this->seen[$kind][$identifier])) {
            throw new InvalidArgumentException(sprintf(
                'Provider registered %s %s more than once.',
                $kind,
                $identifier,
            ));
        }
        if ($this->strict && ($this->expected[$kind][$identifier] ?? null) !== $actual) {
            throw new InvalidArgumentException(sprintf(
                'Provider %s contribution %s does not match its manifest declaration.',
                $kind,
                $identifier,
            ));
        }
        $this->seen[$kind][$identifier] = true;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('The extension contribution phase is closed.');
        }
    }

    /**
     * @param iterable<ContributionDefinition> $items
     * @return array<string, array<string, mixed>>
     */
    private function index(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[$item->identifier()] = $item->toArray();
        }
        return $result;
    }
}
