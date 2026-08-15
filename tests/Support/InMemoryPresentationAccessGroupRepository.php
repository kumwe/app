<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroupRepository;

/**
 * Deterministic in-memory presentation access-group projection for application tests.
 *
 * Tests seed the same typed groups production projects from identity roles and identify each user's
 * assignments by stable group ID. Requested locks are recorded as semantic strings so a test can prove
 * an authorization path asked for a locked read without depending on Doctrine SQL.
 *
 * @since  2.0.0
 */
final class InMemoryPresentationAccessGroupRepository implements PresentationAccessGroupRepository
{
    /**
     * Groups keyed by stable presentation identity.
     *
     * @var    array<string, PresentationAccessGroup>
     * @since  2.0.0
     */
    private array $groups = [];

    /**
     * Stable group identities assigned to each user.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private array $memberships;

    /**
     * Semantic descriptions of requested pessimistic reads, in call order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $locks = [];

    /**
     * Seed typed access groups and direct user assignments.
     *
     * @param  list<PresentationAccessGroup>  $groups       Available role projections.
     * @param  array<string, list<string>>     $memberships  User UUID to stable group identifiers.
     *
     * @throws  InvalidArgumentException  When a group is duplicated or an assignment names an absent group.
     *
     * @since  2.0.0
     */
    public function __construct(array $groups = [], array $memberships = [])
    {
        foreach ($groups as $group) {
            if (isset($this->groups[$group->id])) {
                throw new InvalidArgumentException('A presentation access group is duplicated in the test store.');
            }
            $this->groups[$group->id] = $group;
        }
        foreach ($memberships as $identifiers) {
            foreach ($identifiers as $identifier) {
                if (!isset($this->groups[$identifier])) {
                    throw new InvalidArgumentException('A test presentation access-group assignment is stale.');
                }
            }
        }
        $this->memberships = $memberships;
    }

    /**
     * Return one user's assigned groups in the same deterministic order as the Doctrine adapter.
     *
     * @param   string  $userId  User whose seeded assignments are projected.
     * @param   bool    $lock    Whether to record a user-scoped pessimistic read.
     *
     * @return  list<PresentationAccessGroup>  Assigned live groups in deterministic display order.
     *
     * @since   2.0.0
     */
    public function listForUser(string $userId, bool $lock = false): array
    {
        if ($lock) {
            $this->locks[] = 'user:' . $userId;
        }
        $groups = [];
        foreach ($this->memberships[$userId] ?? [] as $identifier) {
            if (isset($this->groups[$identifier])) {
                $groups[] = $this->groups[$identifier];
            }
        }

        return self::ordered($groups);
    }

    /**
     * Return every seeded group in deterministic display order.
     *
     * @param   bool  $lock  Whether to record a projection-wide pessimistic read.
     *
     * @return  list<PresentationAccessGroup>  All live groups.
     *
     * @since   2.0.0
     */
    public function listAll(bool $lock = false): array
    {
        if ($lock) {
            $this->locks[] = 'all';
        }

        return self::ordered(array_values($this->groups));
    }

    /**
     * Check one stable identity against the seeded live groups.
     *
     * @param   string  $identifier  Candidate stable group identity.
     * @param   bool    $lock        Whether to record an identity-scoped pessimistic read.
     *
     * @return  bool  True when the group is present.
     *
     * @since   2.0.0
     */
    public function exists(string $identifier, bool $lock = false): bool
    {
        if (PresentationAccessGroup::roleIdFromIdentifier($identifier) === null) {
            return false;
        }
        if ($lock) {
            $this->locks[] = 'group:' . $identifier;
        }

        return isset($this->groups[$identifier]);
    }

    /**
     * Return the pessimistic reads requested since construction.
     *
     * @return  list<string>  Semantic lock observations in call order.
     *
     * @since   2.0.0
     */
    public function locks(): array
    {
        return $this->locks;
    }

    /**
     * Sort projections exactly as the production role queries do.
     *
     * @param   list<PresentationAccessGroup>  $groups  Groups to put into display order.
     *
     * @return  list<PresentationAccessGroup>  Groups ordered by name, code, then role UUID.
     *
     * @since   2.0.0
     */
    private static function ordered(array $groups): array
    {
        usort(
            $groups,
            static fn (PresentationAccessGroup $left, PresentationAccessGroup $right): int => [
                $left->name,
                $left->code,
                $left->roleId,
            ] <=> [
                $right->name,
                $right->code,
                $right->roleId,
            ],
        );

        return $groups;
    }
}
