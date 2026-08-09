<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;

/**
 * The contributions one package declares, parsed, ordered, and checked for internal consistency.
 *
 * This is the declaration half of the extension contribution contract. A manifest is signed and can be
 * inspected before any of the package's code runs, so what it promises here is what the runtime later
 * holds the provider to — the set is built once and then only compared against. Construction accepts
 * nothing that would already be incoherent: identifiers must sit in the owner's namespace, none may be
 * declared twice, and navigation and routes may only reference a workspace, capability, or view
 * declared in this same set.
 *
 * Entries are indexed and sorted by identifier, so two manifests listing the same contributions in a
 * different order produce the same exports and the same reconciliation outcome.
 *
 * @since  2.0.0
 */
final readonly class ManifestContributionSet
{
    /**
     * Version of the contribution service-provider interface this class reads and writes.
     *
     * Bumping it is how a future incompatible manifest shape is separated from this one; a manifest
     * declaring any other version is refused rather than interpreted.
     *
     * @var    int
     * @since  2.0.0
     */
    public const SPI_VERSION = 1;

    /**
     * Declared permission codes, keyed and sorted by capability identifier.
     *
     * @var    array<string, CapabilityDefinition>
     * @since  2.0.0
     */
    private array $capabilities;

    /**
     * Declared administrator workspaces, keyed and sorted by workspace identifier.
     *
     * @var    array<string, AdministratorWorkspaceDefinition>
     * @since  2.0.0
     */
    private array $workspaces;

    /**
     * Declared administrator navigation entries, keyed and sorted by item identifier.
     *
     * @var    array<string, AdministratorNavigationDefinition>
     * @since  2.0.0
     */
    private array $navigation;

    /**
     * Declared administrator routes, keyed and sorted by route name.
     *
     * @var    array<string, AdministratorRouteDefinition>
     * @since  2.0.0
     */
    private array $routes;

    /**
     * Declared administrator views, keyed and sorted by view name.
     *
     * @var    array<string, AdministratorViewDefinition>
     * @since  2.0.0
     */
    private array $views;

    /**
     * Declared field types, keyed and sorted by field-type identifier.
     *
     * @var    array<string, FieldTypeDefinition>
     * @since  2.0.0
     */
    private array $fieldTypes;

    /**
     * Declared entity types, keyed and sorted by definition handle.
     *
     * @var    array<string, EntityTypeDefinition>
     * @since  2.0.0
     */
    private array $businessDefinitions;

    /**
     * Assemble one package's declarations and reject any set that is already inconsistent.
     *
     * Called directly only for an empty or hand-built set, such as core's; a real manifest arrives
     * through `fromManifest()`. Business identifiers are checked against the business context's own
     * owner, which is why a field type or entity type belonging to another package fails here.
     *
     * @param   ContributionOwner                            $owner                Package declaring all of it.
     * @param   iterable<CapabilityDefinition>               $capabilities         Permission codes it adds.
     * @param   iterable<AdministratorWorkspaceDefinition>   $workspaces           Administrator groupings.
     * @param   iterable<AdministratorNavigationDefinition>  $navigation           Menu entries it adds.
     * @param   iterable<AdministratorRouteDefinition>       $routes               Guarded routes it serves.
     * @param   iterable<AdministratorViewDefinition>        $views                Templates its routes render.
     * @param   iterable<FieldTypeDefinition>                $fieldTypes           Field types it publishes.
     * @param   iterable<EntityTypeDefinition>               $businessDefinitions  Entity types it publishes.
     *
     * @throws  InvalidArgumentException  When an identifier is outside the owner's namespace or declared twice,
     *          when navigation or a route references something this set does not declare, or when a business
     *          definition names another owner.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ContributionOwner $owner,
        iterable $capabilities = [],
        iterable $workspaces = [],
        iterable $navigation = [],
        iterable $routes = [],
        iterable $views = [],
        iterable $fieldTypes = [],
        iterable $businessDefinitions = [],
    ) {
        $this->capabilities = $this->index($capabilities, 'capability');
        $this->workspaces = $this->index($workspaces, 'workspace');
        $this->navigation = $this->index($navigation, 'navigation');
        $this->routes = $this->index($routes, 'route');
        $this->views = $this->index($views, 'view');
        $this->fieldTypes = $this->businessIndex($fieldTypes, 'field type');
        $this->businessDefinitions = $this->businessIndex($businessDefinitions, 'business definition');

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
        $businessOwner = $owner->identifier() === ContributionOwner::CORE
            ? DefinitionOwner::core()
            : DefinitionOwner::extension($owner->identifier());
        foreach ($this->fieldTypes as $fieldType) {
            $businessOwner->assertOwns($fieldType->id);
        }
        foreach ($this->businessDefinitions as $definition) {
            $businessOwner->assertOwns($definition->handle);
            if ($definition->owner->toArray() !== $businessOwner->toArray()) {
                throw new InvalidArgumentException('A business definition contribution has inconsistent ownership.');
            }
        }
    }

    /**
     * Read a schema-2 manifest's `contributions` object into a checked declaration set.
     *
     * The parsing is deliberately unforgiving, because this is the boundary where untrusted package
     * metadata becomes objects the shell will act on: unknown keys are rejected rather than ignored,
     * every list is capped at 128 entries, every scalar is type-checked, and each identifier is
     * asserted against the declaring package's namespace before the set is assembled.
     *
     * @param   ExtensionIdentifier  $extension  Package the manifest belongs to, which owns everything in it.
     * @param   array<mixed>         $data       The manifest's decoded `contributions` value.
     *
     * @return  self  The package's declarations, indexed and consistency-checked.
     *
     * @throws  InvalidArgumentException  When the SPI version is not 1, a key or value has the wrong shape,
     *          a list is over its cap, or an identifier is not the package's to claim.
     *
     * @since   2.0.0
     */
    public static function fromManifest(ExtensionIdentifier $extension, array $data): self
    {
        $data = self::object($data, 'contributions');
        self::knownKeys($data, ['version', 'capabilities', 'administrator', 'business'], 'contributions');
        if (($data['version'] ?? null) !== self::SPI_VERSION) {
            throw new InvalidArgumentException('The extension contribution SPI version must be 1.');
        }
        $owner = ContributionOwner::extension($extension->value());
        $administrator = self::object($data['administrator'] ?? [], 'contributions.administrator');
        self::knownKeys($administrator, ['workspaces', 'navigation', 'routes', 'views'], 'administrator contributions');
        $business = self::object($data['business'] ?? [], 'contributions.business');
        self::knownKeys($business, ['field_types', 'definitions'], 'business contributions');

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

        $businessOwner = DefinitionOwner::extension($extension->value());
        $fieldTypes = array_map(static function (array $item) use ($businessOwner): FieldTypeDefinition {
            $definition = FieldTypeDefinition::fromArray($item);
            $businessOwner->assertOwns($definition->id);
            return $definition;
        }, self::objects($business['field_types'] ?? [], 'contributions.business.field_types'));
        $businessDefinitions = array_map(static function (array $item) use (
            $businessOwner,
        ): EntityTypeDefinition {
            $definition = EntityTypeDefinition::fromArray($item);
            if ($definition->owner->toArray() !== $businessOwner->toArray()) {
                throw new InvalidArgumentException('A contributed business definition must belong to its package.');
            }
            return $definition;
        }, self::objects($business['definitions'] ?? [], 'contributions.business.definitions'));

        return new self(
            $owner,
            $capabilities,
            $workspaces,
            $navigation,
            $routes,
            $views,
            $fieldTypes,
            $businessDefinitions,
        );
    }

    /**
     * The empty declaration set a schema-1 package stands in with.
     *
     * Schema 1 cannot publish any of these surfaces, so the permission list a caller passes is taken
     * and ignored rather than translated into capabilities. The point is that a legacy package can
     * travel the same code path as a schema-2 one instead of every caller branching on the schema.
     *
     * @param   ExtensionIdentifier  $extension    Package the empty set is attributed to.
     * @param   list<string>         $permissions  The manifest's schema-1 permission codes; not read.
     *
     * @return  self  A set owned by that package and declaring nothing.
     *
     * @since   2.0.0
     */
    public static function legacy(ExtensionIdentifier $extension, array $permissions): self
    {
        $owner = ContributionOwner::extension($extension->value());
        return new self($owner);
    }

    /**
     * The permission codes this package declared.
     *
     * @return  list<CapabilityDefinition>  In capability-identifier order; empty when none were declared.
     *
     * @since   2.0.0
     */
    public function capabilities(): array
    {
        return array_values($this->capabilities);
    }

    /**
     * The administrator workspaces this package declared.
     *
     * @return  list<AdministratorWorkspaceDefinition>  In workspace-identifier order, not display priority.
     *
     * @since   2.0.0
     */
    public function workspaces(): array
    {
        return array_values($this->workspaces);
    }

    /**
     * The administrator navigation entries this package declared.
     *
     * @return  list<AdministratorNavigationDefinition>  In item-identifier order, not display priority.
     *
     * @since   2.0.0
     */
    public function navigation(): array
    {
        return array_values($this->navigation);
    }

    /**
     * The guarded administrator routes this package declared.
     *
     * @return  list<AdministratorRouteDefinition>  In route-name order; each names a view and capability
     *          declared in this same set.
     *
     * @since   2.0.0
     */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    /**
     * The administrator views this package declared.
     *
     * @return  list<AdministratorViewDefinition>  In view-name order; empty when the package serves no pages.
     *
     * @since   2.0.0
     */
    public function views(): array
    {
        return array_values($this->views);
    }

    /**
     * The field types this package declared.
     *
     * Installation reads this to synchronize the persisted field-type catalog, so it is consulted even
     * for a package that is installed but never activated.
     *
     * @return  list<FieldTypeDefinition>  In field-type-identifier order.
     *
     * @since   2.0.0
     */
    public function fieldTypes(): array
    {
        return array_values($this->fieldTypes);
    }

    /**
     * The entity types this package declared.
     *
     * Installation reads this to synchronize the persisted definition catalog, so it is consulted even
     * for a package that is installed but never activated.
     *
     * @return  list<EntityTypeDefinition>  In definition-handle order.
     *
     * @since   2.0.0
     */
    public function businessDefinitions(): array
    {
        return array_values($this->businessDefinitions);
    }

    /**
     * Write the set back out in the same shape `fromManifest()` reads.
     *
     * The runtime publication carries this rather than the original manifest text, so the structure has
     * to round-trip: the compiled map is re-parsed at load and compared with the installed manifest
     * before any of the package's code is allowed to run. Deterministic ordering is what makes that
     * comparison meaningful.
     *
     * @return  array{
     *              version: int,
     *              capabilities: list<array<string, mixed>>,
     *              administrator: array{
     *                  workspaces: list<array<string, mixed>>,
     *                  navigation: list<array<string, mixed>>,
     *                  routes: list<array<string, mixed>>,
     *                  views: list<array<string, mixed>>
     *              },
     *              business: array{
     *                  field_types: list<array<string, mixed>>,
     *                  definitions: list<array<string, mixed>>
     *              }
     *          }
     *
     * @since   2.0.0
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
            'business' => [
                'field_types' => array_map(
                    static fn (FieldTypeDefinition $item): array => $item->toArray(),
                    $this->fieldTypes(),
                ),
                'definitions' => array_map(
                    static fn (EntityTypeDefinition $item): array => $item->toArray(),
                    $this->businessDefinitions(),
                ),
            ],
        ];
    }

    /**
     * Key one kind of declaration by identifier, refusing anything unowned or repeated.
     *
     * Sorting on the way in is what makes the exports, and therefore reconciliation, independent of the
     * order the manifest happened to list things in.
     *
     * @template T of ContributionDefinition
     *
     * @param   iterable<T>  $items  Declarations of one kind, as the manifest listed them.
     * @param   string       $kind   Kind name used in the ownership check and the failure message.
     *
     * @return  array<string, T>  The declarations keyed by identifier, sorted by that key.
     *
     * @throws  InvalidArgumentException  When an identifier is outside the owner's namespace or repeated.
     *
     * @since   2.0.0
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

    /**
     * Key business declarations the same way, reading the identifier field each type actually has.
     *
     * Ownership is deliberately not checked here: business identifiers belong to a `DefinitionOwner`,
     * so the constructor asserts them against that owner once both indexes exist.
     *
     * @template T of FieldTypeDefinition|EntityTypeDefinition
     *
     * @param   iterable<T>  $items  Declared field types or entity types, as the manifest listed them.
     * @param   string       $kind   Kind name used in the failure message.
     *
     * @return  array<string, T>  The declarations keyed by id or handle, sorted by that key.
     *
     * @throws  InvalidArgumentException  When the same id or handle is declared more than once.
     *
     * @since   2.0.0
     */
    private function businessIndex(iterable $items, string $kind): array
    {
        $result = [];
        foreach ($items as $item) {
            $identifier = $item instanceof FieldTypeDefinition ? $item->id : $item->handle;
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

    /**
     * Insist a decoded manifest value is a JSON object rather than a list.
     *
     * An empty array decodes ambiguously, so it is accepted as the empty object an omitted section
     * would have produced.
     *
     * @param   mixed   $value  Decoded manifest value to check.
     * @param   string  $field  Dotted manifest path, used only to name the field in the failure message.
     *
     * @return  array<string, mixed>  The same value, now known to be keyed.
     *
     * @throws  InvalidArgumentException  When the value is not an array, or is a non-empty list.
     *
     * @since   2.0.0
     */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException(sprintf('%s must be an object.', $field));
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Insist a decoded manifest value is a bounded list of JSON objects.
     *
     * The 128-entry cap is a denial-of-service guard: manifest parsing happens before the package is
     * trusted, so a declaration list is never allowed to be unbounded work.
     *
     * @param   mixed   $value  Decoded manifest value to check.
     * @param   string  $field  Dotted manifest path, used only to name the field in the failure message.
     *
     * @return  list<array<string, mixed>>  The entries in manifest order, each known to be keyed.
     *
     * @throws  InvalidArgumentException  When the value is not a list, holds more than 128 entries, or
     *          contains anything that is not an object.
     *
     * @since   2.0.0
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
     * Reject a manifest object carrying any key this version does not understand.
     *
     * Refusing the unknown rather than ignoring it means a package built against a later SPI fails
     * visibly at install instead of silently losing the part of its declaration nothing here reads.
     * The first unknown key in sorted order is named, so the message is stable across runs.
     *
     * @param   array<string, mixed>  $values   Decoded manifest object to inspect.
     * @param   list<string>          $allowed  Every key this version accepts at that position.
     * @param   string                $field    Manifest section named in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When any key falls outside the allowed set.
     *
     * @since   2.0.0
     */
    private static function knownKeys(array $values, array $allowed, string $field): void
    {
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new InvalidArgumentException(sprintf('%s contains unknown key %s.', $field, $unknown[0]));
        }
    }

    /**
     * Read a required non-empty string field out of a decoded manifest object.
     *
     * @param   array<string, mixed>  $values  Decoded manifest object holding the field.
     * @param   string                $field   Key to read, also named in the failure message.
     *
     * @return  string  The value with surrounding whitespace removed.
     *
     * @throws  InvalidArgumentException  When the key is absent, not a string, or blank once trimmed.
     *
     * @since   2.0.0
     */
    private static function string(array $values, string $field): string
    {
        $value = $values[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be a non-empty string.', $field));
        }
        return trim($value);
    }

    /**
     * Read an optional string field, treating absence and emptiness alike.
     *
     * @param   array<string, mixed>  $values  Decoded manifest object that may hold the field.
     * @param   string                $field   Key to read, also named in the failure message.
     *
     * @return  string  The trimmed value, or an empty string when the key was not present.
     *
     * @throws  InvalidArgumentException  When the key is present but not a string.
     *
     * @since   2.0.0
     */
    private static function optionalString(array $values, string $field): string
    {
        $value = $values[$field] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be a string.', $field));
        }
        return trim($value);
    }

    /**
     * Read a required integer field out of a decoded manifest object.
     *
     * A numeric string is not coerced, so a priority written as `"10"` in a manifest is a declaration
     * error rather than a silently accepted value.
     *
     * @param   array<string, mixed>  $values  Decoded manifest object holding the field.
     * @param   string                $field   Key to read, also named in the failure message.
     *
     * @return  int  The value exactly as decoded.
     *
     * @throws  InvalidArgumentException  When the key is absent or is not an integer.
     *
     * @since   2.0.0
     */
    private static function integer(array $values, string $field): int
    {
        $value = $values[$field] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be an integer.', $field));
        }
        return $value;
    }
}
