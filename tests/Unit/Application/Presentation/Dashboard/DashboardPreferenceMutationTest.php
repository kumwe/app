<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Presentation\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceMutation;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies dashboard mutation commands stay within the exact editable slots, scopes, and catalogue bounds.
 *
 * @since  2.0.0
 */
#[CoversClass(DashboardPreferenceMutation::class)]
#[UsesClass(PresentationAccessGroup::class)]
#[UsesClass(SurfaceId::class)]
final class DashboardPreferenceMutationTest extends TestCase
{
    /**
     * Proves canonical personal saves and access-group resets retain their typed command state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAcceptsCanonicalSaveAndResetCommands(): void
    {
        $save = new DashboardPreferenceMutation(
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            2,
            false,
            ['core.dashboard.access-context', 'core.dashboard.content-summary'],
            ['core.dashboard.content-summary'],
        );
        $reset = new DashboardPreferenceMutation(
            CustomizationSlot::NavigationShortcuts,
            CustomizationScope::RoleWorkspace,
            'role:018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            1,
            true,
            [],
            [],
        );

        self::assertSame(['core.dashboard.content-summary'], $save->selectedIds);
        self::assertSame(CustomizationSlot::NavigationShortcuts, $reset->slot);
        self::assertTrue($reset->reset);
    }

    /**
     * Proves every command-owned target, concurrency, reset, and selection invariant fails closed.
     *
     * @param   CustomizationSlot   $slot             Candidate editable slot.
     * @param   CustomizationScope  $scope            Candidate hierarchy scope.
     * @param   string              $scopeId          Candidate actor or access-group identity.
     * @param   int                 $expectedVersion  Candidate optimistic concurrency version.
     * @param   bool                $reset            Whether the candidate represents deletion.
     * @param   list<string>        $submittedIds     Candidate live form identifiers.
     * @param   list<string>        $selectedIds      Candidate checked identifiers.
     * @param   string              $message          Stable refusal message fragment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidCommands')]
    public function testRejectsInvalidCommandState(
        CustomizationSlot $slot,
        CustomizationScope $scope,
        string $scopeId,
        int $expectedVersion,
        bool $reset,
        array $submittedIds,
        array $selectedIds,
        string $message,
    ): void {
        try {
            new DashboardPreferenceMutation(
                $slot,
                $scope,
                $scopeId,
                $expectedVersion,
                $reset,
                $submittedIds,
                $selectedIds,
            );
            self::fail('An invalid dashboard preference command was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * Supply malformed commands spanning every mutation-owned invariant.
     *
     * @return  iterable<string, array{
     *              CustomizationSlot,
     *              CustomizationScope,
     *              string,
     *              int,
     *              bool,
     *              list<string>,
     *              list<string>,
     *              string
     *          }>  Named invalid command arguments.
     *
     * @since   2.0.0
     */
    public static function invalidCommands(): iterable
    {
        $actor = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
        yield 'unsupported slot' => [
            CustomizationSlot::Columns,
            CustomizationScope::User,
            $actor,
            0,
            false,
            [],
            [],
            'slot is invalid',
        ];
        yield 'unsupported scope' => [
            CustomizationSlot::DashboardCards,
            CustomizationScope::Site,
            'default',
            0,
            false,
            [],
            [],
            'scope is invalid',
        ];
        yield 'empty identity' => [
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            '',
            0,
            false,
            [],
            [],
            'scope identity is invalid',
        ];
        yield 'noncanonical access group' => [
            CustomizationSlot::DashboardCards,
            CustomizationScope::RoleWorkspace,
            'role:operations',
            0,
            false,
            [],
            [],
            'access-group identity is invalid',
        ];
        yield 'negative version' => [
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            $actor,
            -1,
            false,
            [],
            [],
            'version is invalid',
        ];
        yield 'reset without stored version' => [
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            $actor,
            0,
            true,
            [],
            [],
            'version is invalid',
        ];
        yield 'reset with stale selection' => [
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            $actor,
            1,
            true,
            ['core.settings'],
            [],
            'reset cannot carry a selection',
        ];
        yield 'selected item outside submitted catalogue' => [
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            $actor,
            0,
            false,
            ['core.settings'],
            ['core.access'],
            'item was not submitted',
        ];
        yield 'duplicate submitted identifier' => [
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            $actor,
            0,
            false,
            ['core.settings', 'core.settings'],
            [],
            'form contains an invalid item',
        ];

        $tooManyShortcuts = array_map(
            static fn (int $index): string => 'core.shortcut-' . $index,
            range(0, 32),
        );
        yield 'shortcut selection above KIS bound' => [
            CustomizationSlot::NavigationShortcuts,
            CustomizationScope::User,
            $actor,
            0,
            false,
            $tooManyShortcuts,
            $tooManyShortcuts,
            'selection exceeds the KIS limit',
        ];
    }
}
