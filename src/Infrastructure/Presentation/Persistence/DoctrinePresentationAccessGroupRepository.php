<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Presentation\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroupRepository;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * DBAL projection of canonical roles and direct user-role assignments into presentation access groups.
 *
 * No presentation-owned membership rows are introduced: group existence comes from `roles`, and user
 * membership comes from `user_roles`. Locking appends `FOR UPDATE` on databases that support it and is a
 * plain deterministic read on SQLite; this adapter never opens or commits the surrounding transaction.
 *
 * @since  2.0.0
 */
final readonly class DoctrinePresentationAccessGroupRepository implements PresentationAccessGroupRepository
{
    /**
     * Bind the projection to the canonical application connection and prefixed table resolver.
     *
     * @param  Connection  $database  DBAL connection carrying caller-owned transactions.
     * @param  TableNames  $tables    Resolver for the installation's physical table prefix.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Project the roles assigned directly to one user.
     *
     * @param   string  $userId  Canonical user UUID whose assignments are selected.
     * @param   bool    $lock    Whether supported databases should hold role and assignment rows.
     *
     * @return  list<PresentationAccessGroup>  Assigned groups ordered by name, code, then UUID.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When a stored role row does not contain strings.
     * @throws  \InvalidArgumentException  When stored role fields violate their canonical schema.
     *
     * @since   2.0.0
     */
    public function listForUser(string $userId, bool $lock = false): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT r.id, r.code, r.name FROM %s r INNER JOIN %s ur ON ur.role_id = r.id '
            . 'WHERE ur.user_id = ? ORDER BY r.name, r.code, r.id%s',
            $this->tables->quoted('roles'),
            $this->tables->quoted('user_roles'),
            $this->lockClause($lock),
        ), [$userId], [Types::GUID]);

        return $this->groups($rows);
    }

    /**
     * Project every canonical role as a presentation access group.
     *
     * @param   bool  $lock  Whether supported databases should hold the selected role rows.
     *
     * @return  list<PresentationAccessGroup>  All groups ordered by name, code, then UUID.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When a stored role row does not contain strings.
     * @throws  \InvalidArgumentException  When stored role fields violate their canonical schema.
     *
     * @since   2.0.0
     */
    public function listAll(bool $lock = false): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, code, name FROM %s ORDER BY name, code, id%s',
            $this->tables->quoted('roles'),
            $this->lockClause($lock),
        ));

        return $this->groups($rows);
    }

    /**
     * Check whether one stable prefixed identity maps to a current role row.
     *
     * @param   string  $identifier  Candidate `role:<uuid>` presentation identity.
     * @param   bool    $lock        Whether supported databases should hold the matching role row.
     *
     * @return  bool  True only when the identifier is canonical and its role exists.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function exists(string $identifier, bool $lock = false): bool
    {
        $roleId = PresentationAccessGroup::roleIdFromIdentifier($identifier);
        if ($roleId === null) {
            return false;
        }
        $stored = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ?%s',
            $this->tables->quoted('roles'),
            $this->lockClause($lock),
        ), [$roleId], [Types::GUID]);

        return is_string($stored) && $stored === $roleId;
    }

    /**
     * Convert driver rows into validated application projections.
     *
     * @param   list<array<string, mixed>>  $rows  Raw role rows returned by DBAL.
     *
     * @return  list<PresentationAccessGroup>  Validated groups preserving query order.
     *
     * @throws  RuntimeException  When a required stored value is not a string.
     * @throws  \InvalidArgumentException  When stored role fields violate their canonical schema.
     *
     * @since   2.0.0
     */
    private function groups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $code = $row['code'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_string($id) || !is_string($code) || !is_string($name)) {
                throw new RuntimeException('A stored presentation access-group role is invalid.');
            }
            $groups[] = PresentationAccessGroup::fromRole($id, $code, $name);
        }

        return $groups;
    }

    /**
     * Return the portable optional pessimistic-lock suffix.
     *
     * @param   bool  $lock  Whether the caller requested a pessimistic read.
     *
     * @return  string  Empty for unlocked reads and SQLite, otherwise `FOR UPDATE` with a leading space.
     *
     * @since   2.0.0
     */
    private function lockClause(bool $lock): string
    {
        return $lock && !($this->database->getDatabasePlatform() instanceof SQLitePlatform)
            ? ' FOR UPDATE'
            : '';
    }
}
