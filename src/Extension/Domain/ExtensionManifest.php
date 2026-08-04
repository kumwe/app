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

    /** @param list<ExtensionDependency> $dependencies */
    public function __construct(
        private ExtensionIdentifier $identifier,
        private ExtensionType $type,
        private SemanticVersion $version,
        private string $serviceProvider,
        private VersionConstraint $kumweCompatibility,
        private VersionConstraint $phpCompatibility,
        array $dependencies = [],
    ) {
        if (strlen($serviceProvider) > 255
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $serviceProvider) !== 1
        ) {
            throw new InvalidArgumentException('The service provider must be a fully qualified PHP class name.');
        }

        $seen = [];

        if (count($dependencies) > 256) {
            throw new InvalidArgumentException('An extension manifest cannot declare more than 256 dependencies.');
        }

        foreach ($dependencies as $dependency) {
            if (!$dependency instanceof ExtensionDependency) {
                throw new InvalidArgumentException('Every manifest dependency must be an ExtensionDependency.');
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

        $this->dependencies = array_values($dependencies);
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

        if (!is_array($dependencyData) || !array_is_list($dependencyData)) {
            throw new InvalidArgumentException('The extension dependencies field must be a JSON array.');
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
