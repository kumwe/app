<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;
use JsonException;
use ValueError;

final readonly class ExtensionManifest
{
    /** @var list<ExtensionDependency> */
    private array $dependencies;

    /** @var array<string, string> */
    private array $autoload;

    /** @var list<class-string> */
    private array $migrations;

    /** @var array<string, mixed> */
    private array $configuration;

    /** @var list<string> */
    private array $permissions;

    /** @var list<array<string, mixed>> */
    private array $routes;

    /** @var list<array<string, mixed>> */
    private array $events;

    /** @var list<string> */
    private array $assets;

    /**
     * @param array<mixed> $dependencies
     * @param array<mixed> $autoload
     * @param array<mixed> $migrations
     * @param array<mixed> $configuration
     * @param array<mixed> $permissions
     * @param array<mixed> $routes
     * @param array<mixed> $events
     * @param array<mixed> $assets
     */
    public function __construct(
        private ExtensionIdentifier $identifier,
        private ExtensionType $type,
        private SemanticVersion $version,
        private string $serviceProvider,
        private VersionConstraint $kumweCompatibility,
        private VersionConstraint $phpCompatibility,
        array $dependencies = [],
        array $autoload = [],
        array $migrations = [],
        array $configuration = [],
        array $permissions = [],
        array $routes = [],
        array $events = [],
        array $assets = [],
    ) {
        if (
            strlen($serviceProvider) > 255
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $serviceProvider) !== 1
        ) {
            throw new InvalidArgumentException('The service provider must be a fully qualified PHP class name.');
        }

        $seen = [];

        if (!array_is_list($dependencies)) {
            throw new InvalidArgumentException('Extension dependencies must be a list.');
        }

        if (count($dependencies) > 256) {
            throw new InvalidArgumentException('An extension manifest cannot declare more than 256 dependencies.');
        }

        foreach ($dependencies as $dependency) {
            if (!($dependency instanceof ExtensionDependency)) {
                throw new InvalidArgumentException('Every extension dependency must be an ExtensionDependency.');
            }

            $dependencyName = $dependency->extension()->value();

            if ($dependency->extension()->equals($identifier)) {
                throw new InvalidArgumentException('An extension cannot depend on itself.');
            }

            if (isset($seen[$dependencyName])) {
                throw new InvalidArgumentException('An extension dependency may only be declared once.');
            }

            $seen[$dependencyName] = true;
        }

        /** @var list<ExtensionDependency> $dependencies */
        $this->dependencies = $dependencies;

        $autoloadMap = [];

        foreach ($autoload as $prefix => $path) {
            if (
                !is_string($prefix)
                || !is_string($path)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\$/D', $prefix) !== 1
                || preg_match('#^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+/$#D', $path) !== 1
                || str_contains($path, '..')
            ) {
                throw new InvalidArgumentException(
                    'Extension PSR-4 autoload entries must use safe prefixes and paths.',
                );
            }

            $autoloadMap[$prefix] = $path;
        }

        ksort($autoloadMap, SORT_STRING);
        $this->autoload = $autoloadMap;
        $this->migrations = $this->classList($migrations, 'migrations');
        $this->configuration = $this->object($configuration, 'configuration');
        $this->permissions = $this->identifierList($permissions, 'permissions');
        $this->routes = $this->objectList($routes, 'routes');
        $this->events = $this->objectList($events, 'events');
        $this->assets = $this->pathList($assets, 'assets');
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 1_048_576) {
            throw new InvalidArgumentException('An extension manifest cannot exceed one mebibyte.');
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The extension manifest must be valid JSON.', 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The extension manifest root must be a JSON object.');
        }

        if (($data['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('The extension manifest schema must be 1.');
        }

        $name = self::requiredString($data, 'name');
        $typeName = self::requiredString($data, 'type');
        $version = self::requiredString($data, 'version');
        $provider = self::requiredString($data, 'provider');
        $requires = self::requiredObject($data, 'requires');
        $kumweConstraint = self::requiredString($requires, 'kumwe');
        $phpConstraint = self::requiredString($requires, 'php');
        $dependencyData = $data['dependencies'] ?? [];
        $autoload = self::requiredObject($data, 'autoload');
        $autoloadData = $autoload['psr-4'] ?? [];
        $migrations = $data['migrations'] ?? [];
        $configuration = $data['configuration'] ?? [];
        $permissions = $data['permissions'] ?? [];
        $routes = $data['routes'] ?? [];
        $events = $data['events'] ?? [];
        $assets = $data['assets'] ?? [];

        if (!is_array($dependencyData) || !array_is_list($dependencyData)) {
            throw new InvalidArgumentException('The extension dependencies field must be a JSON array.');
        }

        if (!is_array($autoloadData) || array_is_list($autoloadData)) {
            throw new InvalidArgumentException('The extension autoload.psr-4 field must be a JSON object.');
        }

        $dependencies = [];

        foreach ($dependencyData as $dependency) {
            if (!is_array($dependency) || array_is_list($dependency)) {
                throw new InvalidArgumentException('Each extension dependency must be a JSON object.');
            }

            $dependencyName = self::requiredString($dependency, 'name');
            $dependencyConstraint = self::requiredString($dependency, 'constraint');

            $optional = $dependency['optional'] ?? false;

            if (!is_bool($optional)) {
                throw new InvalidArgumentException('A dependency optional flag must be a boolean.');
            }

            $dependencies[] = new ExtensionDependency(
                ExtensionIdentifier::fromString($dependencyName),
                VersionConstraint::fromString($dependencyConstraint),
                $optional,
            );
        }

        try {
            $type = ExtensionType::from($typeName);
        } catch (ValueError $exception) {
            throw new InvalidArgumentException('The extension manifest type is not supported.', 0, $exception);
        }

        return new self(
            ExtensionIdentifier::fromString($name),
            $type,
            SemanticVersion::fromString($version),
            $provider,
            VersionConstraint::fromString($kumweConstraint),
            VersionConstraint::fromString($phpConstraint),
            $dependencies,
            $autoloadData,
            is_array($migrations) ? $migrations : throw new InvalidArgumentException('Migrations must be a list.'),
            is_array($configuration)
                ? $configuration
                : throw new InvalidArgumentException('Configuration must be an object.'),
            is_array($permissions) ? $permissions : throw new InvalidArgumentException('Permissions must be a list.'),
            is_array($routes) ? $routes : throw new InvalidArgumentException('Routes must be a list.'),
            is_array($events) ? $events : throw new InvalidArgumentException('Events must be a list.'),
            is_array($assets) ? $assets : throw new InvalidArgumentException('Assets must be a list.'),
        );
    }

    public function identifier(): ExtensionIdentifier
    {
        return $this->identifier;
    }

    public function type(): ExtensionType
    {
        return $this->type;
    }

    public function version(): SemanticVersion
    {
        return $this->version;
    }

    public function serviceProvider(): string
    {
        return $this->serviceProvider;
    }

    public function supports(SemanticVersion $kumweVersion, SemanticVersion $phpVersion): bool
    {
        return $this->kumweCompatibility->accepts($kumweVersion)
            && $this->phpCompatibility->accepts($phpVersion);
    }

    /** @return list<ExtensionDependency> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /** @return array<string, string> */
    public function autoload(): array
    {
        return $this->autoload;
    }

    /** @return list<class-string> */
    public function migrations(): array
    {
        return $this->migrations;
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        return $this->configuration;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /** @return list<array<string, mixed>> */
    public function routes(): array
    {
        return $this->routes;
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return list<string> */
    public function assets(): array
    {
        return $this->assets;
    }

    /**
     * @param array<mixed> $values
     * @return list<class-string>
     */
    private function classList(array $values, string $field): array
    {
        if (!array_is_list($values) || count($values) > 256) {
            throw new InvalidArgumentException(sprintf('Extension %s must be a list of at most 256 classes.', $field));
        }
        $result = [];
        foreach ($values as $value) {
            if (
                !is_string($value)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $value) !== 1
            ) {
                throw new InvalidArgumentException(sprintf('Extension %s contains an invalid class.', $field));
            }
            /** @var class-string $value */
            $result[] = $value;
        }
        return $result;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function object(array $value, string $field): array
    {
        if (array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException(sprintf('Extension %s must be a JSON object.', $field));
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function identifierList(array $values, string $field): array
    {
        if (!array_is_list($values) || count($values) > 256) {
            throw new InvalidArgumentException(sprintf('Extension %s must be a list.', $field));
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[a-z][a-z0-9._-]{1,190}$/D', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('Extension %s contains an invalid identifier.', $field));
            }
            $result[] = $value;
        }
        return array_values(array_unique($result));
    }

    /**
     * @param array<mixed> $values
     * @return list<array<string, mixed>>
     */
    private function objectList(array $values, string $field): array
    {
        if (!array_is_list($values) || count($values) > 256) {
            throw new InvalidArgumentException(sprintf('Extension %s must be a list.', $field));
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_array($value) || array_is_list($value)) {
                throw new InvalidArgumentException(sprintf('Every extension %s entry must be an object.', $field));
            }
            /** @var array<string, mixed> $value */
            $result[] = $value;
        }
        return $result;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function pathList(array $values, string $field): array
    {
        if (!array_is_list($values) || count($values) > 512) {
            throw new InvalidArgumentException(sprintf('Extension %s must be a list.', $field));
        }
        $result = [];
        foreach ($values as $value) {
            if (
                !is_string($value)
                || preg_match('#^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$#D', $value) !== 1
                || str_contains($value, '..')
            ) {
                throw new InvalidArgumentException(sprintf('Extension %s contains an unsafe path.', $field));
            }
            $result[] = $value;
        }
        return array_values(array_unique($result));
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function requiredObject(array $data, string $field): array
    {
        $value = $data[$field] ?? null;

        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('The extension manifest %s field must be an object.', $field));
        }

        return $value;
    }

    /** @param array<mixed> $data */
    private static function requiredString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The extension manifest %s field must be a string.', $field));
        }

        return $value;
    }
}
