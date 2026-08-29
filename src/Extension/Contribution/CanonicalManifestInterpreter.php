<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use LogicException;
use Kumwe\App\Application\Authorization\AuthorizationDefinitionLifecycle;
use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\App\BusinessRecord\Domain\MoneyRateProviderDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\InterfaceStandard\SurfaceDefinition;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionDeclaration;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewDeclaration;

/**
 * Applies host-domain meaning to the SDK's one canonical manifest contribution graph.
 *
 * This class never accepts manifest JSON or an unvalidated declaration array. Structural parsing,
 * ownership, duplicate detection, generation grammar and cross-reference validation belong solely to
 * `ManifestContributions`; the App reads that immutable result and constructs only the policy/domain
 * values its own registries and persistence services need.
 *
 * @since  2.0.0
 */
final readonly class CanonicalManifestInterpreter
{
    /**
     * Bind host interpretation to one canonical SDK graph.
     *
     * @param  ManifestContributions  $canonical  SDK-validated package declaration graph.
     *
     * @since  2.0.0
     */
    public function __construct(private ManifestContributions $canonical)
    {
    }

    /**
     * Create an interpreter from an already parsed SDK manifest.
     *
     * @param   ExtensionManifest  $manifest  Canonical SDK manifest.
     *
     * @return  self  Host semantic view of its canonical contributions.
     *
     * @since   2.0.0
     */
    public static function fromManifest(ExtensionManifest $manifest): self
    {
        return new self($manifest->contributions());
    }

    /**
     * Return the exact canonical package declaration graph.
     *
     * @return  array<string, mixed>  Canonical package-owned declaration graph.
     *
     * @since   2.0.0
     */
    public function declarations(): array
    {
        return $this->canonical->declarations();
    }

    /**
     * Interpret capabilities as App-owned authorization-policy values.
     *
     * @return  list<CapabilityDefinition>  Host authorization definitions.
     *
     * @since   2.0.0
     */
    public function capabilities(): array
    {
        return array_map(
            static fn (array $item): CapabilityDefinition => new CapabilityDefinition(
                self::string($item, 'id'),
                self::string($item, 'label'),
                self::string($item, 'description'),
                self::strings($item, 'allowed_scopes', ['global', 'site']),
                self::boolean($item, 'delegatable', true),
                self::boolean($item, 'high_impact', false),
                AuthorizationDefinitionLifecycle::from(self::string($item, 'lifecycle', 'active')),
                self::integer($item, 'version', 1),
            ),
            $this->list('capabilities'),
        );
    }

    /**
     * Interpret resource policies as App-owned authorization values.
     *
     * @return  list<ResourcePolicyDefinition>  Host resource-policy definitions.
     *
     * @since   2.0.0
     */
    public function resourcePolicies(): array
    {
        return array_map(static function (array $item): ResourcePolicyDefinition {
            $resources = array_map(
                static fn (array $resource): ResourcePolicyTarget => new ResourcePolicyTarget(
                    self::string($resource, 'type'),
                    self::strings($resource, 'identifiers'),
                ),
                self::canonicalList($item['resources'] ?? null, 'resource policy resources'),
            );

            return new ResourcePolicyDefinition(
                self::string($item, 'id'),
                self::string($item, 'capability'),
                $resources,
                self::boolean($item, 'installation_global', false),
                [],
                AuthorizationDefinitionLifecycle::from(self::string($item, 'lifecycle', 'active')),
                self::integer($item, 'version', 1),
            );
        }, $this->list('resource_policies'));
    }

    /**
     * Interpret host business field-type values.
     *
     * @return  list<FieldTypeDefinition>  Host business-schema definitions.
     *
     * @since   2.0.0
     */
    public function fieldTypes(): array
    {
        $owner = DefinitionOwner::extension($this->canonical->owner->identifier());

        return array_map(static function (array $item) use ($owner): FieldTypeDefinition {
            $definition = FieldTypeDefinition::fromArray($item);
            $owner->assertOwns($definition->id);

            return $definition;
        }, $this->list('business', 'field_types'));
    }

    /**
     * Interpret host business entity definitions.
     *
     * @return  list<EntityTypeDefinition>  Host business-definition values.
     *
     * @since   2.0.0
     */
    public function businessDefinitions(): array
    {
        $owner = DefinitionOwner::extension($this->canonical->owner->identifier());

        return array_map(static function (array $item) use ($owner): EntityTypeDefinition {
            $definition = EntityTypeDefinition::fromArray($item);
            if ($definition->owner->toArray() !== $owner->toArray()) {
                throw new LogicException('A canonical business definition changed package ownership.');
            }

            return $definition;
        }, $this->list('business', 'definitions'));
    }

    /**
     * Interpret host semantic interface declarations.
     *
     * @return  list<SurfaceDefinition>  Host KIS declarations.
     *
     * @since   2.0.0
     */
    public function interfaceSurfaces(): array
    {
        $owner = $this->canonical->owner;

        return array_map(
            static fn (array $item): SurfaceDefinition => SurfaceDefinition::fromArray($owner, $item),
            $this->list('interface', 'surfaces'),
        );
    }

    /**
     * Interpret host policy contracts for executable custom views.
     *
     * @return  list<CustomBusinessViewDeclaration>  Signed view contracts.
     *
     * @since   2.0.0
     */
    public function customBusinessViews(): array
    {
        return array_map(
            static fn (array $item): CustomBusinessViewDeclaration =>
                CustomBusinessViewDeclaration::fromManifest($item),
            $this->list('business', 'view_handlers'),
        );
    }

    /**
     * Interpret host policy contracts for executable custom actions.
     *
     * @return  list<CustomBusinessActionDeclaration>  Signed action contracts.
     *
     * @since   2.0.0
     */
    public function customBusinessActions(): array
    {
        return array_map(
            static fn (array $item): CustomBusinessActionDeclaration =>
                CustomBusinessActionDeclaration::fromManifest($item),
            $this->list('business', 'action_handlers'),
        );
    }

    /**
     * Interpret host event-schema declarations.
     *
     * @return  list<EventSchemaDefinition>  Host event contracts.
     *
     * @since   2.0.0
     */
    public function eventSchemas(): array
    {
        return array_map(
            static fn (array $item): EventSchemaDefinition => EventSchemaDefinition::fromArray($item),
            $this->list('integration', 'event_schemas'),
        );
    }

    /**
     * Interpret host queue-policy declarations.
     *
     * @return  list<QueueContributionDefinition>  Host queue definitions.
     *
     * @since   2.0.0
     */
    public function queues(): array
    {
        return array_map(
            static fn (array $item): QueueContributionDefinition => QueueContributionDefinition::fromArray($item),
            $this->list('integration', 'queues'),
        );
    }

    /**
     * Interpret host schedule-policy declarations.
     *
     * @return  list<ScheduleContributionDefinition>  Host schedule definitions.
     *
     * @since   2.0.0
     */
    public function schedules(): array
    {
        return array_map(
            static fn (array $item): ScheduleContributionDefinition => ScheduleContributionDefinition::fromArray($item),
            $this->list('integration', 'schedules'),
        );
    }

    /**
     * Interpret host report declarations.
     *
     * @return  list<ReportDefinition>  Host report definitions.
     *
     * @since   2.0.0
     */
    public function reports(): array
    {
        return array_map(
            static fn (array $item): ReportDefinition => ReportDefinition::fromArray($item),
            $this->list('integration', 'reports'),
        );
    }

    /**
     * Interpret host money-rate provider policy declarations.
     *
     * @return  list<MoneyRateProviderDefinition>  Host rate-provider definitions.
     *
     * @since   2.0.0
     */
    public function moneyRateProviders(): array
    {
        return array_map(
            static fn (array $item): MoneyRateProviderDefinition => MoneyRateProviderDefinition::fromArray($item),
            $this->list('integration', 'rate_providers'),
        );
    }

    /**
     * Interpret host unit-conversion provider policy declarations.
     *
     * @return  list<UnitConversionProviderDefinition>  Host unit-provider definitions.
     *
     * @since   2.0.0
     */
    public function unitConversionProviders(): array
    {
        return array_map(
            static fn (array $item): UnitConversionProviderDefinition =>
                UnitConversionProviderDefinition::fromArray($item),
            $this->list('integration', 'unit_converters'),
        );
    }

    /**
     * Interpret host content-publication declarations.
     *
     * @return  list<TranslationGroupDeclaration>  Host translation-group definitions.
     *
     * @since   2.0.0
     */
    public function contentTranslationGroups(): array
    {
        return array_map(
            static fn (array $item): TranslationGroupDeclaration => TranslationGroupDeclaration::fromArray($item),
            $this->list('content', 'translation_groups'),
        );
    }

    /**
     * Read a list from the already-validated canonical graph and detect only impossible SDK corruption.
     *
     * @param   string  ...$path  Canonical object members ending in one list.
     *
     * @return  list<array<string, mixed>>
     *
     * @since   2.0.0
     */
    private function list(string ...$path): array
    {
        $value = $this->canonical->declarations();
        foreach ($path as $member) {
            if (!is_array($value) || !array_key_exists($member, $value)) {
                return [];
            }
            $value = $value[$member];
        }

        return self::canonicalList($value, implode('.', $path));
    }

    /**
     * Require a canonical SDK graph member to remain a list of objects.
     *
     * @param   mixed   $value  Canonical member value.
     * @param   string  $path   Diagnostic graph path.
     *
     * @return  list<array<string, mixed>>  Canonical object list.
     *
     * @since   2.0.0
     */
    private static function canonicalList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new LogicException(sprintf('The validated SDK contribution graph changed shape at %s.', $path));
        }
        foreach ($value as $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                throw new LogicException(sprintf('The validated SDK contribution graph changed shape at %s.', $path));
            }
        }

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * Read one canonical string member, applying a declared SDK default when absent.
     *
     * @param   array<string, mixed>  $item     Canonical declaration object.
     * @param   string                $member   Member name.
     * @param   ?string               $default  Canonical optional default.
     *
     * @return  string  Canonical string value.
     *
     * @since   2.0.0
     */
    private static function string(array $item, string $member, ?string $default = null): string
    {
        $value = $item[$member] ?? $default;
        if (!is_string($value)) {
            throw new LogicException(sprintf('The validated SDK contribution member %s changed type.', $member));
        }

        return $value;
    }

    /**
     * Read one canonical boolean member, applying a declared SDK default when absent.
     *
     * @param   array<string, mixed>  $item     Canonical declaration object.
     * @param   string                $member   Member name.
     * @param   ?bool                 $default  Canonical optional default.
     *
     * @return  bool  Canonical boolean value.
     *
     * @since   2.0.0
     */
    private static function boolean(array $item, string $member, ?bool $default = null): bool
    {
        $value = $item[$member] ?? $default;
        if (!is_bool($value)) {
            throw new LogicException(sprintf('The validated SDK contribution member %s changed type.', $member));
        }

        return $value;
    }

    /**
     * Read one canonical integer member, applying a declared SDK default when absent.
     *
     * @param   array<string, mixed>  $item     Canonical declaration object.
     * @param   string                $member   Member name.
     * @param   ?int                  $default  Canonical optional default.
     *
     * @return  int  Canonical integer value.
     *
     * @since   2.0.0
     */
    private static function integer(array $item, string $member, ?int $default = null): int
    {
        $value = $item[$member] ?? $default;
        if (!is_int($value)) {
            throw new LogicException(sprintf('The validated SDK contribution member %s changed type.', $member));
        }

        return $value;
    }

    /**
     * Read one canonical string list, applying a declared SDK default when absent.
     *
     * @param   array<string, mixed>  $item     Canonical declaration object.
     * @param   string                $member   Member name.
     * @param   list<string>|null     $default  Canonical optional default.
     *
     * @return  list<string>  Canonical string values.
     *
     * @since   2.0.0
     */
    private static function strings(array $item, string $member, ?array $default = null): array
    {
        $value = $item[$member] ?? $default;
        if (!is_array($value) || !array_is_list($value)) {
            throw new LogicException(sprintf('The validated SDK contribution member %s changed type.', $member));
        }
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new LogicException(sprintf('The validated SDK contribution member %s changed type.', $member));
            }
        }

        /** @var list<string> $value */
        return $value;
    }
}
