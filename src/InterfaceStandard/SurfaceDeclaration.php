<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Typed semantic candidate for one core, extension, or installable-template interface surface.
 *
 * Construction settles local safety and type invariants only. `SurfaceConformanceValidator` evaluates
 * the relationships between intent, pattern, actor, area, states, customization, and responsive
 * behavior before `SurfaceDefinition` admits the candidate into Kumwe's contribution architecture.
 *
 * No property can carry markup, a template path, a query, code, or an unbounded expression. Concrete
 * Twig, CSS, and client behavior remain implementation details of the active conforming template.
 *
 * @since  2.0.0
 */
final readonly class SurfaceDeclaration
{
    /**
     * Exact top-level keys accepted from canonical manifest or database-authored metadata.
     *
     * Requiring an explicit null icon keeps canonical bytes stable while preserving surfaces that do
     * not appear in navigation. Unknown keys fail closed instead of becoming unsafe rendering slots.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const KEYS = [
        'surface',
        'standard',
        'area',
        'actor',
        'intent',
        'resource',
        'purpose',
        'pattern',
        'capabilities',
        'states',
        'customization',
        'responsive',
        'icon',
    ];

    /**
     * Build a locally safe typed declaration without yet admitting its cross-field semantics.
     *
     * @param   ContributionOwner                   $owner          Core or extension identity that owns the surface.
     * @param   SurfaceId                           $surface        Stable identifier inside the owner's namespace.
     * @param   InterfaceStandardVersion            $standard       Exact KIS contract revision.
     * @param   SurfaceArea                         $area           Shell or template delivery area.
     * @param   SurfaceActor                        $actor          Human actor whose task the surface supports.
     * @param   SurfaceIntent                       $intent         Semantic task independent of visual layout.
     * @param   ResourceName                        $resource       Business resource the task operates on.
     * @param   string                              $purpose        Plain-language primary task sentence.
     * @param   SurfacePattern                      $pattern        Approved interaction composition selected.
     * @param   list<Capability>                    $capabilities   Policy requirements applied before rendering.
     * @param   list<SurfaceState>                  $states         Data and authorization states explicitly covered.
     * @param   list<CustomizationPermission>       $customization  Whitelisted presentation choices and scopes.
     * @param   list<ResponsiveElement>             $responsive     Semantic collapse and reflow priorities.
     * @param   ?IconName                           $icon           Theme registry key, or null for no surface icon.
     *
     * @throws  InvalidArgumentException  When ownership, purpose, or collection uniqueness is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ContributionOwner $owner,
        public SurfaceId $surface,
        public InterfaceStandardVersion $standard,
        public SurfaceArea $area,
        public SurfaceActor $actor,
        public SurfaceIntent $intent,
        public ResourceName $resource,
        public string $purpose,
        public SurfacePattern $pattern,
        public array $capabilities,
        public array $states,
        public array $customization,
        public array $responsive,
        public ?IconName $icon,
    ) {
        $owner->assertOwns($surface->value(), 'interface surface');
        self::assertListBounds($capabilities, 'capabilities', 0, 64);
        self::assertListBounds($states, 'states', 1, count(SurfaceState::cases()));
        self::assertListBounds($customization, 'customization', 0, count(CustomizationSlot::cases()));
        self::assertListBounds($responsive, 'responsive', 1, 64);
        if (
            $purpose !== trim($purpose)
            || $purpose === ''
            || mb_strlen($purpose) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $purpose) === 1
            || preg_match('/[<>{}]|javascript:|data:text\/html|<\?/i', $purpose) === 1
        ) {
            throw new InvalidArgumentException(
                'A KIS surface purpose must be plain text containing 1 to 255 characters.',
            );
        }

        self::assertUnique(
            array_map(static fn (Capability $capability): string => $capability->value(), $capabilities),
            'capability',
        );
        self::assertUnique(
            array_map(static fn (SurfaceState $state): string => $state->value, $states),
            'state',
        );
        self::assertUnique(
            array_map(
                static fn (CustomizationPermission $permission): string => $permission->slot->value,
                $customization,
            ),
            'customization slot',
        );
        self::assertUnique(
            array_map(
                static fn (ResponsiveElement $element): string => $element->element->value(),
                $responsive,
            ),
            'responsive element',
        );
    }

    /**
     * Parse strict canonical metadata into a typed semantic declaration.
     *
     * This is the untrusted manifest and persistence boundary. Missing, unknown, unversioned, malformed,
     * or executable-shaped data is rejected before conformance admission; no unrecognized value is
     * preserved for a renderer to interpret later.
     *
     * @param   ContributionOwner    $owner  Core or extension owner supplied by the existing contribution phase.
     * @param   array<string, mixed>  $data   Exact canonical KIS declaration document.
     *
     * @return  self  Locally safe typed candidate ready for conformance validation.
     *
     * @throws  InvalidArgumentException  When keys, types, enum values, identifiers, or nested entries are invalid.
     *
     * @since   2.0.0
     */
    public static function fromArray(ContributionOwner $owner, array $data): self
    {
        self::assertExactKeys($data, self::KEYS, 'surface declaration');

        $standard = InterfaceStandardVersion::tryFrom(self::string($data, 'standard'));
        if ($standard === null) {
            throw new InvalidArgumentException('The KIS standard version is unsupported.');
        }
        $area = SurfaceArea::tryFrom(self::string($data, 'area'));
        if ($area === null) {
            throw new InvalidArgumentException('The KIS surface area is unsupported.');
        }
        $actor = SurfaceActor::tryFrom(self::string($data, 'actor'));
        if ($actor === null) {
            throw new InvalidArgumentException('The KIS surface actor is unsupported.');
        }
        $intent = SurfaceIntent::tryFrom(self::string($data, 'intent'));
        if ($intent === null) {
            throw new InvalidArgumentException('The KIS surface intent is unsupported.');
        }
        $pattern = SurfacePattern::tryFrom(self::string($data, 'pattern'));
        if ($pattern === null) {
            throw new InvalidArgumentException('The KIS surface pattern is unsupported.');
        }

        return new self(
            $owner,
            SurfaceId::fromString(self::string($data, 'surface')),
            $standard,
            $area,
            $actor,
            $intent,
            ResourceName::fromString(self::string($data, 'resource')),
            self::string($data, 'purpose'),
            $pattern,
            self::capabilities($data),
            self::states($data),
            self::customization($data),
            self::responsive($data),
            self::icon($data),
        );
    }

    /**
     * Export a deterministic semantic document without the externally supplied owner identity.
     *
     * The owner remains the owner-bound registrar's responsibility, matching every existing extension
     * contribution definition. Unordered sets are sorted so signed manifest comparison is byte-stable.
     *
     * @return  array{
     *              surface: string,
     *              standard: string,
     *              area: string,
     *              actor: string,
     *              intent: string,
     *              resource: string,
     *              purpose: string,
     *              pattern: string,
     *              capabilities: list<string>,
     *              states: list<string>,
     *              customization: list<array{slot: string, scope: string}>,
     *              responsive: list<array{element: string, priority: string, may_collapse: bool}>,
     *              icon: ?string
     *          }  Canonical manifest and inventory shape.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        $capabilities = array_map(
            static fn (Capability $capability): string => $capability->value(),
            $this->capabilities,
        );
        sort($capabilities, SORT_STRING);
        $states = array_map(static fn (SurfaceState $state): string => $state->value, $this->states);
        sort($states, SORT_STRING);
        $customization = array_map(
            static fn (CustomizationPermission $permission): array => $permission->toArray(),
            $this->customization,
        );
        usort(
            $customization,
            static fn (array $left, array $right): int => $left['slot'] <=> $right['slot'],
        );
        $responsive = array_map(
            static fn (ResponsiveElement $element): array => $element->toArray(),
            $this->responsive,
        );
        usort(
            $responsive,
            static fn (array $left, array $right): int => $left['element'] <=> $right['element'],
        );

        return [
            'surface' => $this->surface->value(),
            'standard' => $this->standard->value,
            'area' => $this->area->value,
            'actor' => $this->actor->value,
            'intent' => $this->intent->value,
            'resource' => $this->resource->value(),
            'purpose' => $this->purpose,
            'pattern' => $this->pattern->value,
            'capabilities' => $capabilities,
            'states' => $states,
            'customization' => $customization,
            'responsive' => $responsive,
            'icon' => $this->icon?->value(),
        ];
    }

    /**
     * Read a required string without silently coercing manifest values.
     *
     * @param   array<string, mixed>  $data  Declaration document being parsed.
     * @param   string                $key   Required top-level key.
     *
     * @return  string  Exact declared string.
     *
     * @throws  InvalidArgumentException  When the value is not a string.
     *
     * @since   2.0.0
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('KIS declaration field %s must be a string.', $key));
        }

        return $value;
    }

    /**
     * Parse capability requirements through the canonical identity-domain value object.
     *
     * @param   array<string, mixed>  $data  Declaration document being parsed.
     *
     * @return  list<Capability>  Normalized capability requirements in declared order.
     *
     * @throws  InvalidArgumentException  When the field is not a list of valid capability strings.
     *
     * @since   2.0.0
     */
    private static function capabilities(array $data): array
    {
        $values = self::list($data, 'capabilities', 0, 64);
        $capabilities = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('KIS capabilities must be a list of strings.');
            }
            $capabilities[] = Capability::fromString($value);
        }

        return $capabilities;
    }

    /**
     * Parse explicitly supported rendering states.
     *
     * @param   array<string, mixed>  $data  Declaration document being parsed.
     *
     * @return  list<SurfaceState>  Typed rendering states in declared order.
     *
     * @throws  InvalidArgumentException  When a value is not a known state string.
     *
     * @since   2.0.0
     */
    private static function states(array $data): array
    {
        $states = [];
        foreach (self::list($data, 'states', 1, count(SurfaceState::cases())) as $value) {
            if (!is_string($value) || SurfaceState::tryFrom($value) === null) {
                throw new InvalidArgumentException('A KIS declaration contains an unsupported surface state.');
            }
            $states[] = SurfaceState::from($value);
        }

        return $states;
    }

    /**
     * Parse whitelisted customization permissions with no arbitrary value slots.
     *
     * @param   array<string, mixed>  $data  Declaration document being parsed.
     *
     * @return  list<CustomizationPermission>  Typed presentation choices in declared order.
     *
     * @throws  InvalidArgumentException  When an entry has unknown keys, types, slots, or scopes.
     *
     * @since   2.0.0
     */
    private static function customization(array $data): array
    {
        $permissions = [];
        foreach (self::list($data, 'customization', 0, count(CustomizationSlot::cases())) as $index => $value) {
            if (!is_array($value)) {
                throw new InvalidArgumentException('KIS customization entries must be objects.');
            }
            self::assertExactKeys($value, ['slot', 'scope'], sprintf('customization[%d]', $index));
            /** @var array<string, mixed> $value */
            $slotValue = self::string($value, 'slot');
            $scopeValue = self::string($value, 'scope');
            $slot = CustomizationSlot::tryFrom($slotValue);
            $scope = CustomizationScope::tryFrom($scopeValue);
            if ($slot === null || $scope === null) {
                throw new InvalidArgumentException('A KIS customization entry contains an unsupported value.');
            }
            $permissions[] = new CustomizationPermission($slot, $scope);
        }

        return $permissions;
    }

    /**
     * Parse responsive priorities as semantic elements rather than selectors or component instructions.
     *
     * @param   array<string, mixed>  $data  Declaration document being parsed.
     *
     * @return  list<ResponsiveElement>  Typed responsive requirements in declared order.
     *
     * @throws  InvalidArgumentException  When an entry has unknown keys, types, or priority values.
     *
     * @since   2.0.0
     */
    private static function responsive(array $data): array
    {
        $elements = [];
        foreach (self::list($data, 'responsive', 1, 64) as $index => $value) {
            if (!is_array($value)) {
                throw new InvalidArgumentException('KIS responsive entries must be objects.');
            }
            self::assertExactKeys(
                $value,
                ['element', 'priority', 'may_collapse'],
                sprintf('responsive[%d]', $index),
            );
            /** @var array<string, mixed> $value */
            $priorityValue = self::string($value, 'priority');
            $priority = ResponsivePriority::tryFrom($priorityValue);
            if ($priority === null || !is_bool($value['may_collapse'])) {
                throw new InvalidArgumentException('A KIS responsive entry contains an unsupported value.');
            }
            $elements[] = new ResponsiveElement(
                ResourceName::fromString(self::string($value, 'element')),
                $priority,
                $value['may_collapse'],
            );
        }

        return $elements;
    }

    /**
     * Parse the optional theme-neutral icon reference.
     *
     * @param   array<string, mixed>  $data  Declaration document being parsed.
     *
     * @return  ?IconName  Typed registry key, or null when the surface needs no icon.
     *
     * @throws  InvalidArgumentException  When a non-null icon is not a string or is unsafe.
     *
     * @since   2.0.0
     */
    private static function icon(array $data): ?IconName
    {
        $value = $data['icon'];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('A KIS icon must be null or a safe registry name.');
        }

        return IconName::fromString($value);
    }

    /**
     * Read a required list without accepting associative or scalar alternatives.
     *
     * @param   array<string, mixed>  $data  Declaration document being parsed.
     * @param   string                $key      Required top-level list key.
     * @param   int                   $minimum  Minimum number of entries.
     * @param   int                   $maximum  Maximum number of entries.
     *
     * @return  list<mixed>  Exact list values.
     *
     * @throws  InvalidArgumentException  When the field is not a bounded array list.
     *
     * @since   2.0.0
     */
    private static function list(array $data, string $key, int $minimum, int $maximum): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('KIS declaration field %s must be a bounded list.', $key));
        }
        self::assertListBounds($value, $key, $minimum, $maximum);

        return $value;
    }

    /**
     * Keep runtime collection budgets identical to the portable JSON Schema limits.
     *
     * @param   array<mixed, mixed>  $values   Candidate collection.
     * @param   string               $key      Field name used in the failure.
     * @param   int                  $minimum  Minimum accepted entries.
     * @param   int                  $maximum  Maximum accepted entries.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the collection is associative or outside its budget.
     *
     * @since   2.0.0
     */
    private static function assertListBounds(array $values, string $key, int $minimum, int $maximum): void
    {
        $count = count($values);
        if (!array_is_list($values) || $count < $minimum || $count > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'KIS declaration field %s must contain between %d and %d list entries.',
                $key,
                $minimum,
                $maximum,
            ));
        }
    }

    /**
     * Reject missing or unknown keys at every declaration level.
     *
     * @param   array<mixed, mixed>  $data     Candidate object.
     * @param   list<string>         $expected Exact accepted key set.
     * @param   string               $context  Object name used in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When keys are missing, unknown, or not strings.
     *
     * @since   2.0.0
     */
    private static function assertExactKeys(array $data, array $expected, string $context): void
    {
        $keys = array_keys($data);
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(sprintf('A KIS %s must use string keys.', $context));
            }
        }
        /** @var list<string> $keys */
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'The KIS %s contains missing or unknown fields.',
                $context,
            ));
        }
    }

    /**
     * Reject repeated entries whose semantics must form a set.
     *
     * @param   list<string>  $values  Canonical values extracted from one declaration collection.
     * @param   string        $kind    Human-readable collection entry kind.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the same value occurs more than once.
     *
     * @since   2.0.0
     */
    private static function assertUnique(array $values, string $kind): void
    {
        if (count($values) !== count(array_unique($values, SORT_STRING))) {
            throw new InvalidArgumentException(sprintf('A KIS declaration repeats a %s.', $kind));
        }
    }
}
