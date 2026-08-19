<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Presentation\Dashboard;

use InvalidArgumentException;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceAccessGroupState;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceQuery;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceState;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\InterfaceStandard\SurfaceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies dashboard preference state cannot misrepresent authorization or bounded browse evidence.
 *
 * @since  2.0.0
 */
#[CoversClass(DashboardPreferenceState::class)]
#[CoversClass(DashboardPreferenceAccessGroupState::class)]
#[UsesClass(DashboardPreferenceQuery::class)]
#[UsesClass(PresentationAccessGroup::class)]
#[UsesClass(SurfaceId::class)]
final class DashboardPreferenceStateTest extends TestCase
{
    /**
     * Proves state always belongs to one identified authenticated actor.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsEmptyActorIdentity(): void
    {
        try {
            new DashboardPreferenceState(
                SurfaceId::fromString('core.administrator.dashboard'),
                '',
                null,
                null,
                [],
                false,
                new DashboardPreferenceQuery(),
                false,
                false,
                false,
            );
            self::fail('Dashboard preference state without an actor identity was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('user identity is invalid', $exception->getMessage());
        }
    }

    /**
     * Proves authorization, previous-page, and browse-limit flags must agree with the validated query.
     *
     * @param   bool    $administration  Whether access-group administration was authorized.
     * @param   int     $page            Validated access-group page represented by the state.
     * @param   bool    $hasPrevious     Candidate previous-page evidence.
     * @param   bool    $hasNext         Candidate next-page evidence.
     * @param   bool    $browseLimit     Candidate targeted-search requirement.
     * @param   string  $message         Stable refusal message fragment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('inconsistentPagingStates')]
    public function testRejectsInconsistentPagingState(
        bool $administration,
        int $page,
        bool $hasPrevious,
        bool $hasNext,
        bool $browseLimit,
        string $message,
    ): void {
        try {
            new DashboardPreferenceState(
                SurfaceId::fromString('core.administrator.dashboard'),
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
                null,
                null,
                [],
                $administration,
                new DashboardPreferenceQuery($page),
                $hasPrevious,
                $hasNext,
                $browseLimit,
            );
            self::fail('Contradictory dashboard preference paging state was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * Supply contradictory paging flags that could otherwise leak or invent role-catalogue state.
     *
     * @return  iterable<string, array{bool, int, bool, bool, bool, string}>  Named invalid state arguments.
     *
     * @since   2.0.0
     */
    public static function inconsistentPagingStates(): iterable
    {
        yield 'unauthorized forward evidence' => [false, 1, false, true, false, 'paging is not authorized'];
        yield 'missing previous evidence' => [true, 2, false, false, false, 'previous-page state is inconsistent'];
        yield 'premature browse limit' => [true, 1, false, false, true, 'browse-limit state is inconsistent'];
    }

    /**
     * Proves a role row from another dashboard surface cannot enter otherwise valid state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAccessGroupStateFromAnotherDashboardSurface(): void
    {
        $group = PresentationAccessGroup::fromRole(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            'operations',
            'Operations',
        );
        $foreign = new DashboardPreferenceAccessGroupState(
            SurfaceId::fromString('core.portal.home'),
            $group,
            null,
            null,
        );
        try {
            new DashboardPreferenceState(
                SurfaceId::fromString('core.administrator.dashboard'),
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
                null,
                null,
                [$foreign],
                true,
                new DashboardPreferenceQuery(),
                false,
                false,
                false,
            );
            self::fail('An access group from another dashboard surface was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('uses another surface', $exception->getMessage());
        }
    }

    /**
     * Proves one state cannot carry more access-group editors than the service page-size contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsMoreThanOneAccessGroupEditor(): void
    {
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $groups = [
            PresentationAccessGroup::fromRole(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
                'operations',
                'Operations',
            ),
            PresentationAccessGroup::fromRole(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb304',
                'reviewers',
                'Reviewers',
            ),
        ];
        $states = array_map(
            static fn (PresentationAccessGroup $group): DashboardPreferenceAccessGroupState =>
                new DashboardPreferenceAccessGroupState($surface, $group, null, null),
            $groups,
        );

        try {
            new DashboardPreferenceState(
                $surface,
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
                null,
                null,
                $states,
                true,
                new DashboardPreferenceQuery(),
                false,
                false,
                false,
            );
            self::fail('An unbounded dashboard access-group editor page was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must be a bounded list', $exception->getMessage());
        }
    }
}
