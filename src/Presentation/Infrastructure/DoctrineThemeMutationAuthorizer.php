<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Presentation\Application\ThemeMutationAuthorizer;
use Kumwe\CMS\Presentation\ThemeSurface;

final readonly class DoctrineThemeMutationAuthorizer implements ThemeMutationAuthorizer
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private AuthorizationGateway $authorization,
    ) {
    }

    public function assertSurface(ExecutionContext $context, ThemeSurface $surface): void
    {
        $capability = 'themes.' . $surface->value . '.manage';
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::item('theme', $surface->value),
        );
        $principal = $context->principal();
        if ($principal === null) {
            return;
        }
        $lock = $this->database->isTransactionActive()
            && !($this->database->getDatabasePlatform() instanceof SQLitePlatform)
            ? ' FOR UPDATE'
            : '';
        $allowed = $this->database->fetchOne(sprintf(
            'SELECT g.id FROM %s u INNER JOIN %s ur ON ur.user_id = u.id '
            . 'INNER JOIN %s g ON g.role_id = ur.role_id '
            . "WHERE u.id = ? AND u.status = 'active' AND g.capability_code = ? "
            . "AND (g.scope_type = 'global' OR (g.scope_type = 'site' AND g.scope_identifier = ?)) "
            . 'ORDER BY g.id LIMIT 1%s',
            $this->tables->quoted('users'),
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
            $lock,
        ), [$principal->subject(), $capability, $context->site()->identifier()]);
        if (!is_string($allowed) || $allowed === '') {
            throw new InsufficientCapability($capability);
        }
    }
}
