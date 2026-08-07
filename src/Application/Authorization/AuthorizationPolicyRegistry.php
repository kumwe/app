<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;

final readonly class AuthorizationPolicyRegistry
{
    /** @var array<string, list<string>> */
    private const ACTION_RESOURCES = [
        'administrator.access' => ['administrator_session'],
        'administrator.bootstrap' => ['administrator'],
        'automation.manage' => ['administrator_session', 'automation_installation', 'job', 'queue', 'schedule'],
        'content.archive' => ['content'],
        'content.create' => ['content'],
        'content.delete' => ['content', 'media'],
        'content.publish' => ['content'],
        'content.read' => ['business_definition', 'content', 'content_type', 'media', 'workflow'],
        'content.restore' => ['content'],
        'content.review' => ['content'],
        'content.submit' => ['content'],
        'content.unpublish' => ['content'],
        'content.update' => ['business_definition', 'content', 'content_type', 'media', 'workflow'],
        'extensions.manage' => ['extension', 'extension_runtime_map', 'extension_trust_key'],
        'navigation.manage' => ['menu', 'menu_item'],
        'settings.manage' => ['site'],
        'system.migrate' => ['database_schema'],
        'system.scheduler.dispatch' => ['schedule'],
        'system.worker.operate' => ['job', 'queue'],
        'themes.administrator.manage' => ['theme'],
        'themes.site.manage' => ['theme'],
        'users.manage' => ['api_token', 'capability', 'grant', 'role', 'site', 'user'],
    ];

    public function supports(Capability $action, AuthorizationResource $resource): bool
    {
        if (!in_array($resource->type(), self::ACTION_RESOURCES[$action->value()] ?? [], true)) {
            return false;
        }

        return match ($action->value()) {
            'themes.site.manage' => $resource->identifier() === 'site',
            'themes.administrator.manage' => $resource->identifier() === 'administrator',
            default => true,
        };
    }

    public function supportsDelegation(Capability $action, GrantScope $scope): bool
    {
        $resources = self::ACTION_RESOURCES[$action->value()] ?? null;
        if ($resources === null || str_starts_with($action->value(), 'system.')) {
            return false;
        }
        if (
            in_array(
                $action->value(),
                ['extensions.manage', 'themes.administrator.manage'],
                true,
            )
            && !$scope->isGlobal()
        ) {
            return false;
        }
        if (
            $action->value() === 'users.manage'
            && !$scope->isGlobal()
        ) {
            return false;
        }
        if ($scope->isGlobal() || $scope->type() === 'site') {
            return true;
        }

        return $scope->identifier() !== null && $this->supports(
            $action,
            AuthorizationResource::item($scope->type(), $scope->identifier()),
        );
    }

    public function requiresGlobalGrant(Capability $action, AuthorizationResource $resource): bool
    {
        return match ($action->value()) {
            'automation.manage' => $resource->type() === 'automation_installation',
            'extensions.manage' => in_array(
                $resource->type(),
                ['extension', 'extension_runtime_map', 'extension_trust_key'],
                true,
            ),
            'themes.administrator.manage' => $resource->type() === 'theme',
            'users.manage' => in_array($resource->type(), ['capability', 'grant', 'role', 'user'], true),
            default => false,
        };
    }
}
