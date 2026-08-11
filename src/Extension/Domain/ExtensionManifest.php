<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use ValueError;

/**
 * Validated in-memory form of an extension's `kumwe.json`, and the only shape the installer trusts.
 *
 * Construction is the validation boundary for everything a package declares about itself. Every
 * collection is checked for shape and bounded in size, and every autoload prefix, migration class,
 * capability identifier, and asset path is matched against a grammar before it is stored, so the
 * installer, the runtime loader, and the contribution registrar all read manifest data without
 * re-checking it. Four schema revisions are accepted: schema 1 predates typed shell contributions and
 * is given an empty contribution set, schema 2 adds closed typed shell/business declarations, and schema
 * 3 adds signed field-presentation and custom business view/action contracts while leaving schema 2's
 * accepted contribution grammar unchanged. Schema 4 adds contribution SPI 2 durable events, automation,
 * projections, reports, and outbound adapters. Strict manifests keep `permissions` identical to their
 * contributed capabilities.
 *
 * @since  2.0.0
 */
final readonly class ExtensionManifest
{
    /**
     * Declared dependencies in manifest order, each extension named at most once and never this one.
     *
     * @var    list<ExtensionDependency>
     * @since  2.0.0
     */
    private array $dependencies;

    /**
     * PSR-4 prefix to package-relative directory, sorted by prefix so the map is deterministic.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $autoload;

    /**
     * Migration classes the installer runs, kept in declaration order because migrations are ordered.
     *
     * @var    list<class-string>
     * @since  2.0.0
     */
    private array $migrations;

    /**
     * Configuration block carried through verbatim; the manifest only guarantees it is an object.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $configuration;

    /**
     * Capability identifiers the extension declares, de-duplicated, in first-seen order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $permissions;

    /**
     * Route declarations left uninterpreted here; each entry is an object, never a list.
     *
     * @var    list<array<string, mixed>>
     * @since  2.0.0
     */
    private array $routes;

    /**
     * Event listener declarations left uninterpreted here; each entry is an object, never a list.
     *
     * @var    list<array<string, mixed>>
     * @since  2.0.0
     */
    private array $events;

    /**
     * Package-relative asset paths, de-duplicated, none of them containing a traversal segment.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $assets;

    /**
     * Typed shell contributions; an empty legacy set when the manifest is schema 1.
     *
     * @var    ManifestContributionSet
     * @since  2.0.0
     */
    private ManifestContributionSet $contributions;

    /**
     * Versioned KIS contract required by a template package; absent for every other extension type.
     *
     * @var    ?TemplateKisCompatibility
     * @since  2.0.0
     */
    private ?TemplateKisCompatibility $templateCompatibility;

    /**
     * Validate and store everything a package declares about itself.
     *
     * Callers that already hold parsed values use this directly; anything starting from a document
     * should go through `fromJson`, which performs the schema-level checks this constructor does not.
     *
     * @param   ExtensionIdentifier        $identifier             Identity the extension registers under.
     * @param   ExtensionType              $type                   Kind of extension the package installs as.
     * @param   SemanticVersion            $version                Version this manifest describes.
     * @param string $serviceProvider Fully qualified provider class the runtime instantiates.
     * @param   VersionConstraint          $kumweCompatibility     Kumwe versions the extension declares support for.
     * @param   VersionConstraint          $phpCompatibility       PHP versions the extension declares support for.
     * @param   array<mixed>               $dependencies           `ExtensionDependency` list, at most 256, no repeats.
     * @param   array<mixed>               $autoload               PSR-4 prefix to package-relative directory map.
     * @param   array<mixed>               $migrations             Migration class names to run, in order.
     * @param   array<mixed>               $configuration          Configuration object, stored as given.
     * @param   array<mixed>               $permissions            Capability identifiers the extension declares.
     * @param   array<mixed>               $routes                 Route declaration objects, at most 256.
     * @param   array<mixed>               $events                 Event declaration objects, at most 256.
     * @param   array<mixed>               $assets                 Package-relative asset paths, at most 512.
     * @param   ?ManifestContributionSet   $contributions          Strict contributions; null selects the legacy set.
     * @param   int                        $schemaVersion          Manifest schema revision; 1 through 4 are supported.
     * @param   ?TemplateKisCompatibility  $templateCompatibility  Closed KIS compatibility contract for templates.
     *
     * @throws  InvalidArgumentException  When the schema is unsupported or any declared value fails its check.
     *
     * @since   2.0.0
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
        ?ManifestContributionSet $contributions = null,
        private int $schemaVersion = 1,
        ?TemplateKisCompatibility $templateCompatibility = null,
    ) {
        if (!in_array($schemaVersion, [1, 2, 3, 4], true)) {
            throw new InvalidArgumentException('The extension manifest schema is unsupported.');
        }
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
        $this->contributions = $contributions ?? ManifestContributionSet::legacy($identifier, $this->permissions);
        if ($type === ExtensionType::Template && $templateCompatibility === null) {
            throw new InvalidArgumentException(
                'A template extension must declare its versioned KIS compatibility contract.',
            );
        }
        if ($type !== ExtensionType::Template && $templateCompatibility !== null) {
            throw new InvalidArgumentException('Only template extensions may declare template compatibility.');
        }
        $this->templateCompatibility = $templateCompatibility;
    }

    /**
     * Parse a `kumwe.json` document into a validated manifest.
     *
     * Decoding is bounded before anything else happens — one mebibyte of input, 32 levels of nesting
     * — so a hostile document cannot exhaust memory on its way to being rejected. A strict
     * document is additionally closed to unknown keys at every level whose key set is known, which
     * turns a misspelled field into an install failure instead of a silently ignored declaration.
     * Schemas 2 through 4 also reconcile `permissions` with contributed capabilities: an absent list is
     * filled in from them, and a present one must match them exactly, order included. Historical
     * schema-one templates without a declaration receive the exact KIS 1.0 compatibility point;
     * template manifests using a strict schema must declare their closed compatibility envelope.
     *
     * @param   string  $json  Raw manifest document read from the package root.
     *
     * @return  self  The validated manifest, with its contribution set resolved for the schema in use.
     *
     * @throws  InvalidArgumentException  When the document is oversized, malformed, or fails any check.
     *
     * @since   2.0.0
     */
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
        /** @var array<string, mixed> $data */

        $schema = $data['schema'] ?? null;
        if (!in_array($schema, [1, 2, 3, 4], true)) {
            throw new InvalidArgumentException('The extension manifest schema must be 1, 2, 3, or 4.');
        }
        if ($schema >= 2) {
            self::assertKnownKeys($data, [
                'schema',
                'name',
                'type',
                'version',
                'provider',
                'requires',
                'dependencies',
                'autoload',
                'migrations',
                'configuration',
                'permissions',
                'routes',
                'events',
                'assets',
                'contributions',
                'template',
            ], 'The extension manifest');
        }

        $name = self::requiredString($data, 'name');
        $typeName = self::requiredString($data, 'type');
        $version = self::requiredString($data, 'version');
        $provider = self::requiredString($data, 'provider');
        $requires = self::requiredObject($data, 'requires');
        if ($schema >= 2) {
            self::assertKnownKeys($requires, ['kumwe', 'php'], 'The extension requirements object');
        }
        $kumweConstraint = self::requiredString($requires, 'kumwe');
        $phpConstraint = self::requiredString($requires, 'php');
        $dependencyData = $data['dependencies'] ?? [];
        $autoload = self::requiredObject($data, 'autoload');
        if ($schema >= 2) {
            self::assertKnownKeys($autoload, ['psr-4'], 'The extension autoload object');
        }
        $autoloadData = $autoload['psr-4'] ?? [];
        $migrations = $data['migrations'] ?? [];
        $configuration = $data['configuration'] ?? [];
        $permissions = $data['permissions'] ?? [];
        $routes = $data['routes'] ?? [];
        $events = $data['events'] ?? [];
        $assets = $data['assets'] ?? [];
        $identifier = ExtensionIdentifier::fromString($name);
        $contributions = $schema >= 2
            ? ManifestContributionSet::fromManifest(
                $identifier,
                self::requiredObject($data, 'contributions'),
                $schema,
            )
            : null;

        if ($schema >= 2 && $contributions !== null) {
            $declaredCapabilities = array_map(
                static fn (\Kumwe\CMS\Extension\Contribution\CapabilityDefinition $definition): string =>
                    $definition->id,
                $contributions->capabilities(),
            );
            if (!array_key_exists('permissions', $data)) {
                $permissions = $declaredCapabilities;
            } elseif (!is_array($permissions) || $permissions !== $declaredCapabilities) {
                throw new InvalidArgumentException(
                    'Strict manifest permissions must exactly match the ordered contributed capability identifiers.',
                );
            }
        }

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
            /** @var array<string, mixed> $dependency */
            if ($schema >= 2) {
                self::assertKnownKeys(
                    $dependency,
                    ['name', 'constraint', 'optional'],
                    'An extension dependency',
                );
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

        $templateDeclaration = $data['template'] ?? null;
        if ($type === ExtensionType::Template && $templateDeclaration === null && $schema >= 2) {
            throw new InvalidArgumentException(
                'A template extension must declare a versioned template compatibility object.',
            );
        }
        if (
            $type === ExtensionType::Template
            && $templateDeclaration !== null
            && (!is_array($templateDeclaration) || array_is_list($templateDeclaration))
        ) {
            throw new InvalidArgumentException('The template compatibility declaration must be a JSON object.');
        }
        if ($type !== ExtensionType::Template && $templateDeclaration !== null) {
            throw new InvalidArgumentException('Only template extensions may declare template compatibility.');
        }
        /** @var ?array<string, mixed> $templateDeclaration */
        $templateCompatibility = match (true) {
            $templateDeclaration !== null => TemplateKisCompatibility::fromArray($templateDeclaration),
            $type === ExtensionType::Template && $schema === 1 => TemplateKisCompatibility::legacyKisOne(),
            default => null,
        };

        return new self(
            $identifier,
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
            $contributions,
            $schema,
            $templateCompatibility,
        );
    }

    /**
     * Report which manifest revision the package was written against.
     *
     * @return  int  1 for legacy, 2 for typed contributions, or 3 for presenters and custom contracts.
     *
     * @since   2.0.0
     */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * Name the extension this manifest describes.
     *
     * @return  ExtensionIdentifier  Identity the registry keys the extension by.
     *
     * @since   2.0.0
     */
    public function identifier(): ExtensionIdentifier
    {
        return $this->identifier;
    }

    /**
     * Report the kind of extension the package installs as.
     *
     * @return  ExtensionType  Kind fixed at first install, which a later upgrade may not change.
     *
     * @since   2.0.0
     */
    public function type(): ExtensionType
    {
        return $this->type;
    }

    /**
     * Report the version the package declares for itself.
     *
     * @return  SemanticVersion  Version an upgrade is compared against.
     *
     * @since   2.0.0
     */
    public function version(): SemanticVersion
    {
        return $this->version;
    }

    /**
     * Name the class the runtime instantiates to let the extension register its services.
     *
     * @return  string  Fully qualified class name; validated as a shape, never checked for existence.
     *
     * @since   2.0.0
     */
    public function serviceProvider(): string
    {
        return $this->serviceProvider;
    }

    /**
     * Decide whether this extension declares support for a given Kumwe and PHP pair.
     *
     * @param   SemanticVersion  $kumweVersion  Kumwe version the extension would run on.
     * @param   SemanticVersion  $phpVersion    PHP version of that runtime.
     *
     * @return  bool  True only when both declared constraints accept their version.
     *
     * @since   2.0.0
     */
    public function supports(SemanticVersion $kumweVersion, SemanticVersion $phpVersion): bool
    {
        return $this->kumweCompatibility->accepts($kumweVersion)
            && $this->phpCompatibility->accepts($phpVersion);
    }

    /**
     * List the extensions that must be present before this one can be enabled.
     *
     * @return  list<ExtensionDependency>  Dependencies in manifest order; empty when the package stands alone.
     *
     * @since   2.0.0
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Report the PSR-4 mapping the runtime registers so the extension's classes can be found.
     *
     * @return  array<string, string>  Namespace prefix to package-relative directory, sorted by prefix.
     *
     * @since   2.0.0
     */
    public function autoload(): array
    {
        return $this->autoload;
    }

    /**
     * List the migration classes the installer runs for this extension.
     *
     * @return  list<class-string>  Migrations in declaration order, which is the order they must run in.
     *
     * @since   2.0.0
     */
    public function migrations(): array
    {
        return $this->migrations;
    }

    /**
     * Report the configuration block the package ships.
     *
     * @return  array<string, mixed>  The object exactly as declared; empty when the package declares none.
     *
     * @since   2.0.0
     */
    public function configuration(): array
    {
        return $this->configuration;
    }

    /**
     * List the capability identifiers the extension declares.
     *
     * @return  list<string>  De-duplicated identifiers; for strict schemas these mirror contributed capabilities.
     *
     * @since   2.0.0
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * List the route declarations the package ships, for the route registrar to interpret.
     *
     * @return  list<array<string, mixed>>  Objects as declared; this type checks their shape, not their content.
     *
     * @since   2.0.0
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * List the event declarations the package ships, for the runtime loader to interpret.
     *
     * @return  list<array<string, mixed>>  Objects as declared; this type checks their shape, not their content.
     *
     * @since   2.0.0
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * List the package-relative asset paths the extension publishes.
     *
     * @return  list<string>  De-duplicated relative paths, each already proven free of traversal.
     *
     * @since   2.0.0
     */
    public function assets(): array
    {
        return $this->assets;
    }

    /**
     * Report the typed contributions the extension makes to the application shell.
     *
     * @return  ManifestContributionSet  Parsed strict contributions; an empty owned set for schema 1.
     *
     * @since   2.0.0
     */
    public function contributions(): ManifestContributionSet
    {
        return $this->contributions;
    }

    /**
     * Report the KIS component and token contract required by a template package.
     *
     * @return  ?TemplateKisCompatibility  Compatibility declaration for templates, null for other types.
     *
     * @since   2.0.0
     */
    public function templateCompatibility(): ?TemplateKisCompatibility
    {
        return $this->templateCompatibility;
    }

    /**
     * Close a strict manifest object to keys it does not define.
     *
     * Unknown keys are sorted before reporting so the same document always names the same key,
     * which keeps the failure message stable across PHP versions and hash orders.
     *
     * @param   array<string, mixed>  $values   Decoded object to inspect.
     * @param   list<string>          $allowed  Keys the schema defines for that object.
     * @param   string                $field    Human-readable name of the object, used in the message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the object carries a key outside the allowed set.
     *
     * @since   2.0.0
     */
    private static function assertKnownKeys(array $values, array $allowed, string $field): void
    {
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new InvalidArgumentException(sprintf('%s contains unknown key %s.', $field, $unknown[0]));
        }
    }

    /**
     * Narrow a declared list to class names, preserving order.
     *
     * The names are validated as shapes only — a namespace-separated PHP identifier of at least two
     * parts — because the classes belong to code that is not autoloadable yet at manifest time.
     *
     * @param   array<mixed>  $values  Declared entries, expected to be a list of at most 256 strings.
     * @param   string        $field   Manifest field being validated, used in the failure message.
     *
     * @return  list<class-string>  The names in declaration order.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded list of qualified class names.
     *
     * @since   2.0.0
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
     * Narrow a declared value to a JSON object.
     *
     * An empty array is accepted, because JSON `{}` and `[]` both decode to it and an absent block
     * is written either way; a non-empty list is not.
     *
     * @param   array<mixed>  $value  Decoded value, expected to be an object.
     * @param   string        $field  Manifest field being validated, used in the failure message.
     *
     * @return  array<string, mixed>  The object as declared, empty when nothing was declared.
     *
     * @throws  InvalidArgumentException  When the value is a non-empty list rather than an object.
     *
     * @since   2.0.0
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
     * Narrow a declared list to dotted lowercase identifiers, dropping repeats.
     *
     * De-duplication keeps first-seen order, so a declaration order that carries meaning to the
     * package survives into the stored list.
     *
     * @param   array<mixed>  $values  Declared entries, expected to be a list of at most 256 strings.
     * @param   string        $field   Manifest field being validated, used in the failure message.
     *
     * @return  list<string>  Distinct identifiers in first-seen order.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded list of valid identifiers.
     *
     * @since   2.0.0
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
     * Narrow a declared list to JSON objects, leaving their contents alone.
     *
     * Repeats are kept, because route and event declarations are positional rather than identified,
     * and nothing here reads far enough into an entry to know when two of them collide.
     *
     * @param   array<mixed>  $values  Declared entries, expected to be a list of at most 256 objects.
     * @param   string        $field   Manifest field being validated, used in the failure message.
     *
     * @return  list<array<string, mixed>>  The entries in declaration order.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded list, or an entry is not an object.
     *
     * @since   2.0.0
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
     * Narrow a declared list to relative paths that are safe to join onto a package root.
     *
     * A path may only use unreserved filename characters and forward slashes, may not begin or end
     * with a slash, and may not contain `..` anywhere — including inside a segment — so no declared
     * asset can reach outside the package. Repeats are dropped, keeping first-seen order.
     *
     * @param   array<mixed>  $values  Declared entries, expected to be a list of at most 512 strings.
     * @param   string        $field   Manifest field being validated, used in the failure message.
     *
     * @return  list<string>  Distinct relative paths in first-seen order.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded list, or a path is unsafe.
     *
     * @since   2.0.0
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
     * Read a field that the manifest schema requires to be an object.
     *
     * A missing field and one holding the wrong type fail identically, so the caller never has to
     * distinguish "absent" from "present but malformed".
     *
     * @param   array<string, mixed>  $data   Decoded object to read the field from.
     * @param   string                $field  Name of the required field.
     *
     * @return  array<string, mixed>  The field's value as an object.
     *
     * @throws  InvalidArgumentException  When the field is absent, not an array, or a JSON list.
     *
     * @since   2.0.0
     */
    private static function requiredObject(array $data, string $field): array
    {
        $value = $data[$field] ?? null;

        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('The extension manifest %s field must be an object.', $field));
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Read a field that the manifest schema requires to be a non-blank string.
     *
     * Blankness is judged after trimming, but the value is returned exactly as written, leaving any
     * surrounding whitespace for the value object that parses it to normalise or reject.
     *
     * @param   array<mixed>  $data   Decoded object to read the field from.
     * @param   string        $field  Name of the required field.
     *
     * @return  string  The field's value, unmodified.
     *
     * @throws  InvalidArgumentException  When the field is absent, not a string, or blank once trimmed.
     *
     * @since   2.0.0
     */
    private static function requiredString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The extension manifest %s field must be a string.', $field));
        }

        return $value;
    }
}
