<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use ValueError;

/**
 * Portable, owner-bound, optimistic-versioned record for one KIS customization slot.
 *
 * Construction and import enforce the complete `presentation-preference.schema.json` contract in PHP,
 * including the conditional value and scope vocabulary. The record contains presentation data only;
 * authorization and current surface admission stay in the mutation service where live policy exists.
 *
 * @since  2.0.0
 */
final readonly class PresentationPreference
{
    /**
     * Portable document schema emitted by this release.
     *
     * @var    int
     * @since  2.0.0
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Interface standard version this record is compatible with.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string STANDARD_VERSION = 'kis-1.0';

    /**
     * Hold fields that `create()` or `fromArray()` have already validated together.
     *
     * @param  SurfaceId                    $surface    Semantic surface receiving the preference.
     * @param  ContributionOwner            $owner      Current owner of that surface declaration.
     * @param  CustomizationScope           $scope      Hierarchy layer this record overrides.
     * @param  ?string                      $scopeId    Site, workspace, or actor identity at that layer.
     * @param  CustomizationSlot            $slot       Closed presentation choice being stored.
     * @param  PresentationPreferenceValue  $value      Slot-specific normalized value.
     * @param  int                          $version    Optimistic row version, starting at one.
     * @param  string                       $updatedBy  Accountable actor for the stored mutation.
     * @param  DateTimeImmutable            $updatedAt  Instant the stored mutation occurred.
     *
     * @since  2.0.0
     */
    private function __construct(
        private SurfaceId $surface,
        private ContributionOwner $owner,
        private CustomizationScope $scope,
        private ?string $scopeId,
        private CustomizationSlot $slot,
        private PresentationPreferenceValue $value,
        private int $version,
        private string $updatedBy,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Create a canonical record from typed runtime input.
     *
     * @param   SurfaceId           $surface    Semantic surface receiving the preference.
     * @param   ContributionOwner   $owner      Current owner of that surface declaration.
     * @param   CustomizationScope  $scope      Hierarchy layer this record overrides.
     * @param   ?string             $scopeId    Site, workspace, or actor identity at that layer.
     * @param   CustomizationSlot   $slot       Closed presentation choice being stored.
     * @param   mixed               $value      Candidate slot-specific value.
     * @param   int                 $version    Optimistic row version, starting at one.
     * @param   string              $updatedBy  Accountable actor for the stored mutation.
     * @param   DateTimeImmutable   $updatedAt  Instant the stored mutation occurred.
     *
     * @return  self  Fully validated portable preference record.
     *
     * @throws  InvalidArgumentException  When ownership, scope, value, version, or attribution is invalid.
     *
     * @since   2.0.0
     */
    public static function create(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationScope $scope,
        ?string $scopeId,
        CustomizationSlot $slot,
        mixed $value,
        int $version,
        string $updatedBy,
        DateTimeImmutable $updatedAt,
    ): self {
        $owner->assertOwns($surface->value(), 'interface surface');
        PresentationPreferenceKey::assertScopeId($scopeId);
        PresentationPreferenceKey::assertScopeIdentity($scope, $scopeId);
        self::assertScopeSlot($scope, $slot);
        if ($version < 1) {
            throw new InvalidArgumentException('A KIS presentation preference version must be at least one.');
        }
        self::assertActor($updatedBy);

        return new self(
            $surface,
            $owner,
            $scope,
            $scopeId,
            $slot,
            PresentationPreferenceValue::from($slot, $value),
            $version,
            $updatedBy,
            $updatedAt,
        );
    }

    /**
     * Parse an exact portable preference document and reject unknown compatibility data.
     *
     * @param   array<string, mixed>  $data  Decoded `presentation-preference.schema.json` document.
     *
     * @return  self  Validated record safe for compatibility checks and rebased import.
     *
     * @throws  InvalidArgumentException  When fields, schema, standard, timestamp, or value are invalid.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data);
        if (($data['schema'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('The KIS presentation preference schema version is unsupported.');
        }
        if (($data['standard'] ?? null) !== self::STANDARD_VERSION) {
            throw new InvalidArgumentException('The KIS presentation preference standard version is unsupported.');
        }
        foreach (['surface', 'owner', 'scope', 'slot', 'updated_by', 'updated_at'] as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new InvalidArgumentException(sprintf('The KIS presentation preference %s is invalid.', $field));
            }
        }
        if (!is_int($data['version'] ?? null)) {
            throw new InvalidArgumentException('The KIS presentation preference version must be an integer.');
        }
        $scopeId = $data['scope_id'] ?? null;
        if (!is_string($scopeId) && $scopeId !== null) {
            throw new InvalidArgumentException('The KIS presentation preference scope identity is invalid.');
        }

        try {
            $scope = CustomizationScope::from($data['scope']);
            $slot = CustomizationSlot::from($data['slot']);
        } catch (ValueError $exception) {
            throw new InvalidArgumentException(
                'The KIS presentation preference slot or scope is unsupported.',
                0,
                $exception,
            );
        }

        return self::create(
            SurfaceId::fromString($data['surface']),
            ContributionOwner::fromString($data['owner']),
            $scope,
            $scopeId,
            $slot,
            $data['value'],
            $data['version'],
            $data['updated_by'],
            self::timestamp($data['updated_at']),
        );
    }

    /**
     * Return the semantic surface this value customizes.
     *
     * @return  SurfaceId
     *
     * @since   2.0.0
     */
    public function surface(): SurfaceId
    {
        return $this->surface;
    }

    /**
     * Return the active contribution owner recorded with the preference.
     *
     * @return  ContributionOwner
     *
     * @since   2.0.0
     */
    public function owner(): ContributionOwner
    {
        return $this->owner;
    }

    /**
     * Return the hierarchy layer carrying this value.
     *
     * @return  CustomizationScope
     *
     * @since   2.0.0
     */
    public function scope(): CustomizationScope
    {
        return $this->scope;
    }

    /**
     * Return the site, workspace, or actor selected inside the layer.
     *
     * @return  ?string  Null only for an installation-global layer.
     *
     * @since   2.0.0
     */
    public function scopeId(): ?string
    {
        return $this->scopeId;
    }

    /**
     * Return the closed presentation choice represented by the record.
     *
     * @return  CustomizationSlot
     *
     * @since   2.0.0
     */
    public function slot(): CustomizationSlot
    {
        return $this->slot;
    }

    /**
     * Return the normalized value for the selected slot.
     *
     * @return  PresentationPreferenceValue
     *
     * @since   2.0.0
     */
    public function value(): PresentationPreferenceValue
    {
        return $this->value;
    }

    /**
     * Return the optimistic row version.
     *
     * @return  int  Positive version incremented exactly once per successful mutation.
     *
     * @since   2.0.0
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Return the actor attributed to the latest stored mutation.
     *
     * @return  string  Bounded actor identifier.
     *
     * @since   2.0.0
     */
    public function updatedBy(): string
    {
        return $this->updatedBy;
    }

    /**
     * Return the instant of the latest stored mutation.
     *
     * @return  DateTimeImmutable
     *
     * @since   2.0.0
     */
    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Export the canonical portable document for backup or validated import.
     *
     * @return  array{
     *              schema: int,
     *              standard: string,
     *              surface: string,
     *              owner: string,
     *              scope: string,
     *              scope_id: ?string,
     *              slot: string,
     *              value: mixed,
     *              version: int,
     *              updated_by: string,
     *              updated_at: string
     *          }  Exact `presentation-preference.schema.json` representation.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'standard' => self::STANDARD_VERSION,
            'surface' => $this->surface->value(),
            'owner' => $this->owner->identifier(),
            'scope' => $this->scope->value,
            'scope_id' => $this->scopeId,
            'slot' => $this->slot->value,
            'value' => $this->value->value(),
            'version' => $this->version,
            'updated_by' => $this->updatedBy,
            'updated_at' => $this->updatedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Enforce the same conditional slot-to-scope vocabulary as the portable JSON Schema.
     *
     * @param   CustomizationScope  $scope  Candidate hierarchy layer.
     * @param   CustomizationSlot   $slot   Candidate presentation slot.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the schema does not admit the pair.
     *
     * @since   2.0.0
     */
    private static function assertScopeSlot(CustomizationScope $scope, CustomizationSlot $slot): void
    {
        $allowed = match ($slot) {
            CustomizationSlot::Columns,
            CustomizationSlot::SavedViews,
            CustomizationSlot::DashboardCards,
            CustomizationSlot::LandingWorkspace => [
                CustomizationScope::Administrator,
                CustomizationScope::RoleWorkspace,
                CustomizationScope::User,
            ],
            CustomizationSlot::Density => CustomizationScope::cases(),
            CustomizationSlot::Layout => [CustomizationScope::Site, CustomizationScope::Administrator],
            CustomizationSlot::ThemeMode => [CustomizationScope::Site, CustomizationScope::User],
            CustomizationSlot::NavigationShortcuts => [
                CustomizationScope::RoleWorkspace,
                CustomizationScope::User,
            ],
            CustomizationSlot::LabelsHelp => [CustomizationScope::Administrator],
        };
        if (!in_array($scope, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'The KIS %s preference is not allowed at the %s scope.',
                $slot->value,
                $scope->value,
            ));
        }
    }

    /**
     * Validate portable audit attribution without inventing a narrower identity vocabulary.
     *
     * @param   string  $actor  Candidate accountable actor.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value violates the JSON Schema string contract.
     *
     * @since   2.0.0
     */
    private static function assertActor(string $actor): void
    {
        $unsafe = preg_match('/[<>{}\x00-\x1F\x7F]/u', $actor);
        if (
            mb_strlen($actor) < 1
            || mb_strlen($actor) > 191
            || $unsafe !== 0
        ) {
            throw new InvalidArgumentException('The KIS presentation preference update actor is invalid.');
        }
    }

    /**
     * Parse an RFC 3339 timestamp without accepting PHP's rollover or natural-language formats.
     *
     * @param   string  $value  Portable date-time string from an imported document or stored row.
     *
     * @return  DateTimeImmutable  Exact represented instant.
     *
     * @throws  InvalidArgumentException  When the value is not a real RFC 3339 date and time.
     *
     * @since   2.0.0
     */
    private static function timestamp(string $value): DateTimeImmutable
    {
        $matched = preg_match(
            '/^(?<date>\d{4}-(?<month>\d{2})-(?<day>\d{2}))T'
            . '(?<time>(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2}))'
            . '(?:\.(?<fraction>\d+))?(?<zone>Z|[+-](?<zone_hour>\d{2}):(?<zone_minute>\d{2}))$/D',
            $value,
            $parts,
        );
        if ($matched !== 1) {
            throw new InvalidArgumentException('The KIS presentation preference timestamp is not RFC 3339.');
        }
        $month = (int) $parts['month'];
        $day = (int) $parts['day'];
        $hour = (int) $parts['hour'];
        $minute = (int) $parts['minute'];
        $second = (int) $parts['second'];
        $zoneHour = isset($parts['zone_hour']) && $parts['zone_hour'] !== '' ? (int) $parts['zone_hour'] : 0;
        $zoneMinute = isset($parts['zone_minute']) && $parts['zone_minute'] !== '' ? (int) $parts['zone_minute'] : 0;
        if (
            !checkdate($month, $day, (int) substr($parts['date'], 0, 4))
            || $hour > 23
            || $minute > 59
            || $second > 59
            || $zoneHour > 23
            || $zoneMinute > 59
        ) {
            throw new InvalidArgumentException('The KIS presentation preference timestamp is invalid.');
        }
        $fraction = isset($parts['fraction']) && $parts['fraction'] !== ''
            ? substr(str_pad($parts['fraction'], 6, '0'), 0, 6)
            : '000000';
        $timestamp = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.uP',
            $parts['date'] . 'T' . $parts['time'] . '.' . $fraction . $parts['zone'],
        );
        if (!$timestamp instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('The KIS presentation preference timestamp is invalid.');
        }

        return $timestamp;
    }

    /**
     * Require an imported document to carry exactly the portable schema fields.
     *
     * @param   array<string, mixed>  $data  Candidate decoded document.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a required field is absent or an unknown field is present.
     *
     * @since   2.0.0
     */
    private static function assertExactKeys(array $data): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        $expected = [
            'owner',
            'schema',
            'scope',
            'scope_id',
            'slot',
            'standard',
            'surface',
            'updated_at',
            'updated_by',
            'value',
            'version',
        ];
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('A KIS presentation preference has unknown or missing fields.');
        }
    }
}
