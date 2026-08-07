<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;

final readonly class ManifestContributionSet
{
    public const SPI_VERSION = 1;

    /** @var array<string, CapabilityDefinition> */
    private array $capabilities;

    /** @var array<string, AdministratorWorkspaceDefinition> */
    private array $workspaces;

    /** @var array<string, AdministratorNavigationDefinition> */
    private array $navigation;

    /** @var array<string, AdministratorRouteDefinition> */
    private array $routes;

    /** @var array<string, AdministratorViewDefinition> */
    private array $views;

    /**
     * @param iterable<CapabilityDefinition> $capabilities
     * @param iterable<AdministratorWorkspaceDefinition> $workspaces
     * @param iterable<AdministratorNavigationDefinition> $navigation
     * @param iterable<AdministratorRouteDefinition> $routes
     * @param iterable<AdministratorViewDefinition> $views
     */
    public function __construct(
        public ContributionOwner $owner,
        iterable $capabilities = [],
        iterable $workspaces = [],
        iterable $navigation = [],
        iterable $routes = [],
        iterable $views = [],
    ) {
        $this->capabilities = $this->index($capabilities, 'capability');
        $this->workspaces = $this->index($workspaces, 'workspace');
        $this->navigation = $this->index($navigation, 'navigation');
        $this->routes = $this->index($routes, 'route');
        $this->views = $this->index($views, 'view');

        foreach ($this->navigation as $item) {
            if (!isset($this->workspaces[$item->workspace])) {
                throw new InvalidArgumentException('Contributed navigation must reference an owned workspace.');
            }
            if (!isset($this->capabilities[$item->capability])) {
                throw new InvalidArgumentException('Contributed navigation must reference a declared capability.');
            }
        }
        foreach ($this->routes as $route) {
            if (!isset($this->capabilities[$route->capability])) {
                throw new InvalidArgumentException('Contributed administrator routes require a declared capability.');
            }
            if (!isset($this->views[$route->view])) {
                throw new InvalidArgumentException('Contributed administrator routes must reference a declared view.');
            }
        }
    }

    /** @param array<mixed> $data */
    public static function fromManifest(ExtensionIdentifier $extension, array $data): self
    {
        $data = self::object($data, 'contributions');
        self::knownKeys($data, ['version', 'capabilities', 'administrator'], 'contributions');
        if (($data['version'] ?? null) !== self::SPI_VERSION) {
            throw new InvalidArgumentException('The extension contribution SPI version must be 1.');
        }
        $owner = ContributionOwner::extension($extension->value());
        $administrator = self::object($data['administrator'] ?? [], 'contributions.administrator');
        self::knownKeys($administrator, ['workspaces', 'navigation', 'routes', 'views'], 'administrator contributions');

        $capabilities = array_map(static function (array $item) use ($owner): CapabilityDefinition {
            self::knownKeys($item, ['id', 'label', 'description'], 'capability contribution');
            $definition = new CapabilityDefinition(
                self::string($item, 'id'),
                self::string($item, 'label'),
                self::string($item, 'description'),
            );
            $owner->assertOwns($definition->id, 'capability');
            return $definition;
        }, self::objects($data['capabilities'] ?? [], 'contributions.capabilities'));

        $workspaces = array_map(static function (array $item) use ($owner): AdministratorWorkspaceDefinition {
            self::knownKeys($item, ['id', 'label', 'description', 'priority'], 'workspace contribution');
            $definition = new AdministratorWorkspaceDefinition(
                self::string($item, 'id'),
                self::string($item, 'label'),
                self::string($item, 'description'),
                self::integer($item, 'priority'),
            );
            $owner->assertOwns($definition->id, 'workspace');
            return $definition;
        }, self::objects($administrator['workspaces'] ?? [], 'contributions.administrator.workspaces'));

        $navigation = array_map(static function (array $item) use ($owner): AdministratorNavigationDefinition {
            self::knownKeys(
                $item,
                ['id', 'workspace', 'label', 'description', 'path', 'icon', 'capability', 'priority', 'keywords'],
                'navigation contribution',
            );
            $definition = new AdministratorNavigationDefinition(
                self::string($item, 'id'),
                self::string($item, 'workspace'),
                self::string($item, 'label'),
                self::string($item, 'description'),
                self::string($item, 'path'),
                self::string($item, 'icon'),
                self::string($item, 'capability'),
                self::integer($item, 'priority'),
                self::optionalString($item, 'keywords'),
            );
            $owner->assertOwns($definition->id, 'navigation');
            $owner->assertOwns($definition->workspace, 'workspace');
            $owner->assertOwns($definition->capability, 'capability');
            return $definition;
        }, self::objects($administrator['navigation'] ?? [], 'contributions.administrator.navigation'));

        $routes = array_map(static function (array $item) use ($owner): AdministratorRouteDefinition {
            self::knownKeys($item, ['name', 'path', 'methods', 'capability', 'view'], 'route contribution');
            $methods = $item['methods'] ?? null;
            $definition = new AdministratorRouteDefinition(
                self::string($item, 'name'),
                self::string($item, 'path'),
                is_array($methods) ? $methods : throw new InvalidArgumentException('Route methods must be a list.'),
                self::string($item, 'capability'),
                self::string($item, 'view'),
            );
            $owner->assertOwns($definition->name, 'route');
            $owner->assertOwns($definition->capability, 'capability');
            $owner->assertOwns($definition->view, 'view');
            return $definition;
        }, self::objects($administrator['routes'] ?? [], 'contributions.administrator.routes'));

        $views = array_map(static function (array $item) use ($owner): AdministratorViewDefinition {
            self::knownKeys($item, ['name', 'template'], 'view contribution');
            $definition = new AdministratorViewDefinition(
                self::string($item, 'name'),
                self::string($item, 'template'),
            );
            $owner->assertOwns($definition->name, 'view');
            return $definition;
        }, self::objects($administrator['views'] ?? [], 'contributions.administrator.views'));

        return new self($owner, $capabilities, $workspaces, $navigation, $routes, $views);
    }

    /** @param list<string> $permissions */
    public static function legacy(ExtensionIdentifier $extension, array $permissions): self
    {
        $owner = ContributionOwner::extension($extension->value());
        return new self($owner);
    }

    /** @return list<CapabilityDefinition> */
    public function capabilities(): array
    {
        return array_values($this->capabilities);
    }

    /** @return list<AdministratorWorkspaceDefinition> */
    public function workspaces(): array
    {
        return array_values($this->workspaces);
    }

    /** @return list<AdministratorNavigationDefinition> */
    public function navigation(): array
    {
        return array_values($this->navigation);
    }

    /** @return list<AdministratorRouteDefinition> */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    /** @return list<AdministratorViewDefinition> */
    public function views(): array
    {
        return array_values($this->views);
    }

    /**
     * @return array{
     *     version: int,
     *     capabilities: list<array<string, mixed>>,
     *     administrator: array{
     *         workspaces: list<array<string, mixed>>,
     *         navigation: list<array<string, mixed>>,
     *         routes: list<array<string, mixed>>,
     *         views: list<array<string, mixed>>
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => self::SPI_VERSION,
            'capabilities' => array_map(
                static fn (CapabilityDefinition $item): array => $item->toArray(),
                $this->capabilities(),
            ),
            'administrator' => [
                'workspaces' => array_map(
                    static fn (AdministratorWorkspaceDefinition $item): array => $item->toArray(),
                    $this->workspaces(),
                ),
                'navigation' => array_map(
                    static fn (AdministratorNavigationDefinition $item): array => $item->toArray(),
                    $this->navigation(),
                ),
                'routes' => array_map(
                    static fn (AdministratorRouteDefinition $item): array => $item->toArray(),
                    $this->routes(),
                ),
                'views' => array_map(
                    static fn (AdministratorViewDefinition $item): array => $item->toArray(),
                    $this->views(),
                ),
            ],
        ];
    }

    /**
     * @template T of ContributionDefinition
     * @param iterable<T> $items
     * @return array<string, T>
     */
    private function index(iterable $items, string $kind): array
    {
        $result = [];
        foreach ($items as $item) {
            $identifier = $item->identifier();
            $this->owner->assertOwns($identifier, $kind);
            if (isset($result[$identifier])) {
                throw new InvalidArgumentException(sprintf(
                    'Contribution %s %s is declared more than once.',
                    $kind,
                    $identifier,
                ));
            }
            $result[$identifier] = $item;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException(sprintf('%s must be an object.', $field));
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private static function objects(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 128) {
            throw new InvalidArgumentException(sprintf('%s must be a list of at most 128 objects.', $field));
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException(sprintf('Every %s entry must be an object.', $field));
            }
            /** @var array<string, mixed> $item */
            $result[] = $item;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $allowed
     */
    private static function knownKeys(array $values, array $allowed, string $field): void
    {
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new InvalidArgumentException(sprintf('%s contains unknown key %s.', $field, $unknown[0]));
        }
    }

    /** @param array<string, mixed> $values */
    private static function string(array $values, string $field): string
    {
        $value = $values[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be a non-empty string.', $field));
        }
        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private static function optionalString(array $values, string $field): string
    {
        $value = $values[$field] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be a string.', $field));
        }
        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $field): int
    {
        $value = $values[$field] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be an integer.', $field));
        }
        return $value;
    }
}
