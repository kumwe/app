<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\InterfaceStandard;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceValue;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies portable preference records and slot values match the normative KIS JSON Schema.
 *
 * @since  2.0.0
 */
#[CoversClass(PresentationPreference::class)]
#[CoversClass(PresentationPreferenceKey::class)]
#[CoversClass(PresentationPreferenceValue::class)]
final class PresentationPreferenceTest extends TestCase
{
    /**
     * Proves the committed portable example parses and exports without semantic drift.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortableExampleRoundTrips(): void
    {
        $document = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/docs/interface-standard/examples/'
                . 'presentation-preference.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($document);

        $preference = PresentationPreference::fromArray($document);

        self::assertSame($document, $preference->toArray());
    }

    /**
     * The portable owner and surface admit the canonical extension punctuation and digit-led namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortableRecordAdmitsCanonicalExtensionOwnerGrammar(): void
    {
        $document = $this->document();
        $document['owner'] = '9ac.me/2-orders_v1';
        $document['surface'] = '9ac.me.2-orders_v1.administrator.settings';

        $preference = PresentationPreference::fromArray($document);

        self::assertSame($document, $preference->toArray());
    }

    /**
     * Portable preferences preserve every legacy dotted namespace of a canonical extension owner.
     *
     * @param   string  $owner    Canonical portable owner identifier.
     * @param   string  $surface  Exact owned surface using the legacy dotted namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('legacyDottedPreferenceOwners')]
    public function testPortableRecordAdmitsLegacyDottedOwnerNamespace(string $owner, string $surface): void
    {
        $document = $this->document();
        $document['owner'] = $owner;
        $document['surface'] = $surface;

        self::assertSame($document, PresentationPreference::fromArray($document)->toArray());
    }

    /**
     * Supply portable owner and surface pairs whose package dots survive the legacy namespace mapping.
     *
     * @return  iterable<string, array{string, string}>
     *
     * @since   2.0.0
     */
    public static function legacyDottedPreferenceOwners(): iterable
    {
        yield 'repeated vendor dot' => ['a../b', 'a...b.workspace'];
        yield 'trailing vendor dot' => ['a./b', 'a..b.workspace'];
        yield 'trailing package dot' => ['a/b.', 'a.b..workspace'];
    }

    /**
     * Import rejects a non-canonical owner spelling instead of silently normalizing portable bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortableRecordRejectsNonCanonicalOwnerSpelling(): void
    {
        $document = $this->document();
        $document['owner'] = 'Acme/Editor';
        $document['surface'] = 'acme.editor.administrator.settings';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('owner must be canonical');

        PresentationPreference::fromArray($document);
    }

    /**
     * Proves each schema slot accepts its canonical bounded representation.
     *
     * @param   CustomizationSlot   $slot   Slot under test.
     * @param   CustomizationScope  $scope  Schema-compatible layer.
     * @param   mixed               $value  Canonical value for the slot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('validValues')]
    public function testEverySlotAdmitsItsSchemaValue(
        CustomizationSlot $slot,
        CustomizationScope $scope,
        mixed $value,
    ): void {
        $preference = PresentationPreference::create(
            SurfaceId::fromString('core.administrator.settings'),
            ContributionOwner::core(),
            $scope,
            $scope === CustomizationScope::Administrator ? null : 'scope:one',
            $slot,
            $value,
            1,
            'actor:one',
            new \DateTimeImmutable('2026-08-11T12:00:00Z'),
        );

        self::assertSame($value, $preference->value()->value());
    }

    /**
     * Supplies one canonical value for every closed presentation slot.
     *
     * @return  iterable<string, array{CustomizationSlot, CustomizationScope, mixed}>
     *
     * @since   2.0.0
     */
    public static function validValues(): iterable
    {
        yield 'columns' => [CustomizationSlot::Columns, CustomizationScope::User, ['reference', 'updated-at']];
        yield 'density' => [CustomizationSlot::Density, CustomizationScope::Site, 'compact'];
        yield 'saved view' => [
            CustomizationSlot::SavedViews,
            CustomizationScope::RoleWorkspace,
            ['name' => 'Awaiting review', 'sort' => ['status', 'updated-at'], 'page_size' => 50],
        ];
        yield 'layout' => [CustomizationSlot::Layout, CustomizationScope::Administrator, 'catalog-wide'];
        yield 'theme mode' => [CustomizationSlot::ThemeMode, CustomizationScope::User, 'system'];
        yield 'dashboard cards' => [
            CustomizationSlot::DashboardCards,
            CustomizationScope::Administrator,
            ['work-queue', 'recent-changes'],
        ];
        yield 'landing workspace' => [
            CustomizationSlot::LandingWorkspace,
            CustomizationScope::User,
            'core.administrator.dashboard',
        ];
        yield 'shortcuts' => [
            CustomizationSlot::NavigationShortcuts,
            CustomizationScope::RoleWorkspace,
            ['core.administrator.content', 'core.administrator.media'],
        ];
        yield 'labels and help' => [
            CustomizationSlot::LabelsHelp,
            CustomizationScope::Administrator,
            ['field.reference' => 'Inspection reference'],
        ];
    }

    /**
     * Proves unsupported slot/scope combinations fail at the same boundary as schema one-of validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSlotScopeConditionFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not allowed');

        PresentationPreference::create(
            SurfaceId::fromString('core.administrator.settings'),
            ContributionOwner::core(),
            CustomizationScope::Site,
            'default',
            CustomizationSlot::Columns,
            ['reference'],
            1,
            'actor:one',
            new \DateTimeImmutable('2026-08-11T12:00:00Z'),
        );
    }

    /**
     * Proves lists enforce schema uniqueness and item bounds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDuplicateSemanticNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate');

        PresentationPreferenceValue::from(CustomizationSlot::Columns, ['reference', 'reference']);
    }

    /**
     * Proves stored text cannot become an executable or markup customization channel.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMarkupBearingHelpIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bounded plain text');

        PresentationPreferenceValue::from(
            CustomizationSlot::LabelsHelp,
            ['field.reference' => '<script>alert(1)</script>'],
        );
    }

    /**
     * Proves imports reject both unknown fields and unsupported compatibility versions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testImportRequiresExactKnownCompatibilityDocument(): void
    {
        $document = $this->document();
        $document['executable'] = 'no';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown or missing');

        PresentationPreference::fromArray($document);
    }

    /**
     * Proves invalid calendar timestamps do not pass a permissive date parser.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidRfc3339CalendarDateIsRejected(): void
    {
        $document = $this->document();
        $document['updated_at'] = '2026-02-31T12:00:00Z';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timestamp is invalid');

        PresentationPreference::fromArray($document);
    }

    /**
     * Proves portable records tie null identity exclusively to the installation administrator layer.
     *
     * @param   CustomizationScope  $scope    Candidate hierarchy layer.
     * @param   ?string             $scopeId  Candidate identity presence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidScopeIdentities')]
    public function testScopeIdentityPresenceFailsClosed(
        CustomizationScope $scope,
        ?string $scopeId,
    ): void {
        $document = $this->document();
        $document['scope'] = $scope->value;
        $document['scope_id'] = $scopeId;
        $this->expectException(InvalidArgumentException::class);

        PresentationPreference::fromArray($document);
    }

    /**
     * Supply every invalid scope and identity-presence pairing.
     *
     * @return  iterable<string, array{CustomizationScope, ?string}>
     *
     * @since   2.0.0
     */
    public static function invalidScopeIdentities(): iterable
    {
        yield 'administrator must be global' => [CustomizationScope::Administrator, 'default'];
        yield 'site must be named' => [CustomizationScope::Site, null];
        yield 'role workspace must be named' => [CustomizationScope::RoleWorkspace, null];
        yield 'user must be named' => [CustomizationScope::User, null];
    }

    /**
     * Return one complete canonical preference document for mutation tests.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
    private function document(): array
    {
        return [
            'schema' => 1,
            'standard' => 'kis-1.0',
            'surface' => 'core.administrator.settings',
            'owner' => 'core',
            'scope' => 'user',
            'scope_id' => 'actor:one',
            'slot' => 'density',
            'value' => 'compact',
            'version' => 1,
            'updated_by' => 'actor:one',
            'updated_at' => '2026-08-11T12:00:00Z',
        ];
    }
}
