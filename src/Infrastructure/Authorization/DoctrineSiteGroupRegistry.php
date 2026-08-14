<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Authorization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Authorization\SiteGroup;
use Kumwe\CMS\Application\Authorization\SiteGroupRegistry;
use Kumwe\CMS\Application\Authorization\SiteGroupUnknown;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/**
 * Read side of the declared-group registry, answered from the prefixed `site_groups` tables.
 *
 * The whole declared set is read in one statement the first time anything asks, then answered from memory
 * for the rest of the process. That is deliberate and is the property the authorization hot path depends
 * on: a containment test must never become a join per decision, and group membership changes are
 * administrative events an operator performs, not transactional state a request mutates. A long-lived
 * process therefore sees a membership change at its next start or after `forget()`, which is the same
 * bargain the compiled extension runtime map already makes.
 *
 * Membership is joined to enabled sites only, so disabling a site withdraws it from every group it was
 * declared in without deleting anything, and a group whose sites have all been disabled resolves to
 * nothing at all rather than to an empty owner.
 *
 * @since  2.0.0
 */
final class DoctrineSiteGroupRegistry implements SiteGroupRegistry
{
    /**
     * Declared groups resolved from storage, keyed by identifier, or null before the first read.
     *
     * @var    ?array<string, SiteGroup>
     * @since  2.0.0
     */
    private ?array $groups = null;

    /**
     * Bind the registry to the connection and table map its lookup runs against.
     *
     * @param  Connection  $database  DBAL connection the group declarations are read from.
     * @param  TableNames  $tables    Resolver applying the configured prefix to the group and site tables.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly Connection $database, private readonly TableNames $tables)
    {
    }

    /**
     * Resolve one declared group and the enabled sites it currently contains.
     *
     * @param   string  $identifier  Group identifier stored on an ownership row or named by an operator.
     *
     * @return  SiteGroup  The declared group, restricted to sites that are currently enabled.
     *
     * @throws  SiteGroupUnknown  When no such group is declared, or none of its members is enabled.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the declaration lookup.
     *
     * @since   2.0.0
     */
    public function group(string $identifier): SiteGroup
    {
        $group = $this->resolved()[strtolower(trim($identifier))] ?? null;
        if ($group === null) {
            throw new SiteGroupUnknown($identifier);
        }

        return $group;
    }

    /**
     * List every declared group that currently resolves.
     *
     * @return  list<SiteGroup>  Groups in identifier order; empty on an installation that declares none.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the declaration lookup.
     *
     * @since   2.0.0
     */
    public function all(): array
    {
        return array_values($this->resolved());
    }

    /**
     * Drop the memoized declarations so the next question re-reads them.
     *
     * Group administration calls this after it commits, so an operator who has just added a site to a
     * group does not have to wait for the next process to see it take effect.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function forget(): void
    {
        $this->groups = null;
    }

    /**
     * Read every declared group and its enabled membership, once per process.
     *
     * The single statement returns one row per member site, ordered so the assembled membership is
     * deterministic. A group whose every member is disabled produces no rows and therefore no group,
     * which is what makes a withdrawn set fail closed instead of resolving to an empty owner.
     *
     * @return  array<string, SiteGroup>  Resolved groups keyed and ordered by identifier.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the declaration lookup.
     *
     * @since   2.0.0
     */
    private function resolved(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT g.identifier AS group_identifier, g.name AS group_name, m.site_identifier AS site_identifier '
            . 'FROM %s g INNER JOIN %s m ON m.group_identifier = g.identifier '
            . 'INNER JOIN %s s ON s.identifier = m.site_identifier '
            . 'WHERE s.enabled = ? ORDER BY g.identifier, m.site_identifier',
            $this->tables->quoted('site_groups'),
            $this->tables->quoted('site_group_members'),
            $this->tables->quoted('sites'),
        ), [true], [Types::BOOLEAN]);

        /** @var array<string, array{name: string, members: list<string>}> $assembled */
        $assembled = [];
        foreach ($rows as $row) {
            $identifier = $row['group_identifier'];
            $name = $row['group_name'];
            $site = $row['site_identifier'];
            if (!is_string($identifier) || !is_string($name) || !is_string($site) || $site === '') {
                continue;
            }
            $assembled[$identifier] ??= ['name' => $name, 'members' => []];
            $assembled[$identifier]['members'][] = $site;
        }

        $groups = [];
        foreach ($assembled as $identifier => $declaration) {
            $groups[$identifier] = new SiteGroup(
                $identifier,
                $declaration['name'],
                $declaration['members'],
            );
        }
        $this->groups = $groups;

        return $groups;
    }
}
