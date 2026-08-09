<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;

/**
 * The closed table of which resources each capability may act on, and how far it may be delegated.
 *
 * `DenyByDefaultAuthorizationGateway` consults this before it looks at a single grant, so a capability
 * aimed at a resource type nobody paired it with is refused however broad the actor's grants are. That
 * makes the table the place to read, and to change, when deciding what a capability actually reaches:
 * it is core product policy, holds no state, and never queries storage. It answers three questions —
 * whether an action and resource belong together at all, whether the action may be granted onward at a
 * given scope, and whether only an installation-wide grant can authorize it.
 *
 * @since  2.0.0
 */
final readonly class AuthorizationPolicyRegistry
{
    /**
     * Every capability the core recognises, mapped to the resource types it is allowed to act on.
     *
     * Keyed by capability value; each list is the complete set of resource types that capability may
     * reach, so a capability absent from the table is supported nowhere and reaches nothing.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const ACTION_RESOURCES = [
        'administrator.access' => ['administrator_session'],
        'administrator.bootstrap' => ['administrator'],
        'automation.manage' => ['administrator_session', 'automation_installation', 'job', 'queue', 'schedule'],
        'business.record.action' => ['business_record'],
        'business.record.archive' => ['business_record'],
        'business.record.browse' => ['business_record'],
        'business.record.create' => ['business_record'],
        'business.record.delete' => ['business_record'],
        'business.record.history' => ['business_record'],
        'business.record.read' => ['business_record'],
        'business.record.relate' => ['business_record'],
        'business.record.restore' => ['business_record'],
        'business.record.update' => ['business_record'],
        'business.schema.approve' => ['business_schema'],
        'business.schema.destructive' => ['business_schema'],
        'business.schema.execute' => ['business_schema'],
        'business.schema.plan' => ['business_schema'],
        'business.schema.read' => ['business_schema'],
        'business.schema.recover' => ['business_schema'],
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

    /**
     * Decide whether an action is meaningful against this resource at all, before any grant is read.
     *
     * Membership in the table is the general rule; theme management is narrowed further because the two
     * theme capabilities are deliberately separate. `themes.site.manage` reaches only the theme resource
     * identified `site` and `themes.administrator.manage` only the one identified `administrator`, so an
     * editor trusted with the public look of a site cannot reach the back-office chrome.
     *
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Resource the action is aimed at.
     *
     * @return  bool  False both for an unknown capability and for a known one aimed at a resource type
     *          it never covers, which the gateway reports as `unsupported_action_resource`.
     *
     * @since   2.0.0
     */
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

    /**
     * Decide whether an action may be granted onward, and at the scope proposed for the grant.
     *
     * Delegation is more restricted than use. Capabilities under the `system.` prefix belong to
     * in-process identities and are never delegatable; `extensions.manage`, `themes.administrator.manage`
     * and `users.manage` reshape the whole installation and may only be delegated globally. A global or
     * site-wide scope is otherwise accepted, and a narrower scope is admitted only when its type and
     * identifier name a resource the action itself supports — which is what stops a content capability
     * being granted against, say, a menu.
     *
     * @param   Capability  $action  Capability the actor proposes to grant onward.
     * @param   GrantScope  $scope   Scope the grant would be written at.
     *
     * @return  bool  True when a grant of this capability at this scope is a shape the core recognises;
     *          whether the actor personally holds enough authority is decided by the gateway.
     *
     * @since   2.0.0
     */
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

    /**
     * Decide whether only an installation-wide grant can authorize this action on this resource.
     *
     * Some resources belong to the installation rather than to a site — the extension registry and its
     * trust keys, the administrator theme, installation-wide automation records, and the identity tables
     * behind users, roles, grants and capabilities. For those the gateway skips its site-ownership match
     * and demands a global grant from the principal, so a grant held over one site can never reach state
     * every site shares.
     *
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Resource the action is aimed at.
     *
     * @return  bool  True when a site-scoped grant must be refused with `global_grant_required`.
     *
     * @since   2.0.0
     */
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
