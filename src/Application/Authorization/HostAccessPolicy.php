<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Access\MembershipRequirement;
use Kumwe\Access\OwnershipScopeRule;
use Kumwe\Access\ResourceOwnershipScopePolicy;

/**
 * The two access-policy tables this build freezes: the membership-sensitive resource types and the
 * reserved ownership rules.
 *
 * `kumwe/access-control` owns the decision algebra, the registries and the ownership vocabulary but
 * deliberately ships no sensitive category and no reserved category: its factories refuse to build the
 * policy registry and the ownership-scope policy until the host states both tables explicitly. This is
 * the one place App states them, as PHP source rather than configuration, so that there is no
 * environment variable, settings row or manifest key that turns a ledger into shared property or lets
 * a business-record capability be delegated without a live membership. The composition root hands the
 * same two tables to the package factories through the `kumwe.access` configuration, the contribution
 * registries build their isolated fallback registry from them, and the retained authorization tests pin
 * their exact contents.
 *
 * @since  2.0.0
 */
final readonly class HostAccessPolicy
{
    /**
     * Resource types a delegated credential may only reach while it carries a live organization membership.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const MEMBERSHIP_RESOURCE_TYPES = [
        'approval_request',
        'business_record',
        'organization',
        'organization_membership',
        'resource_policy',
        'separation_duty_rule',
        'workspace',
    ];

    /**
     * Categories whose ownership rule is fixed by this build and cannot be declared, widened, or overridden.
     *
     * The first seven rows are the business-group table decided in ADR 0001: a legal entity's books,
     * ledgers and pay runs are owned by one site only, while the master data four businesses share —
     * clients, people, price lists, products and services — may be widened to a declared group. The rest
     * are the categories core itself carries, listed in full so the contract is complete rather than
     * exemplary, and so an extension cannot quietly reclassify one of them.
     *
     * @var    array<string, OwnershipScopeRule>
     * @since  2.0.0
     */
    private const RESERVED_OWNERSHIP_RULES = [
        'accounting_document' => OwnershipScopeRule::SiteOnly,
        'ledger' => OwnershipScopeRule::SiteOnly,
        'pay_run' => OwnershipScopeRule::SiteOnly,
        'client' => OwnershipScopeRule::SiteOrGroup,
        'person' => OwnershipScopeRule::SiteOrGroup,
        'price_list' => OwnershipScopeRule::SiteOrGroup,
        'product_service' => OwnershipScopeRule::SiteOrGroup,
        'site_group' => OwnershipScopeRule::SiteOrGroup,
        'administrator' => OwnershipScopeRule::SiteOnly,
        'administrator_session' => OwnershipScopeRule::SiteOnly,
        'api_token' => OwnershipScopeRule::SiteOnly,
        'approval_request' => OwnershipScopeRule::SiteOnly,
        'audit_trail' => OwnershipScopeRule::SiteGroupOrInstallation,
        'automation_installation' => OwnershipScopeRule::SiteGroupOrInstallation,
        'business_definition' => OwnershipScopeRule::SiteOnly,
        'business_record' => OwnershipScopeRule::SiteOnly,
        'business_report' => OwnershipScopeRule::SiteOnly,
        'business_schema' => OwnershipScopeRule::SiteOnly,
        'capability' => OwnershipScopeRule::SiteGroupOrInstallation,
        'content' => OwnershipScopeRule::SiteOnly,
        'content_type' => OwnershipScopeRule::SiteOnly,
        'database_schema' => OwnershipScopeRule::SiteOnly,
        'extension' => OwnershipScopeRule::SiteGroupOrInstallation,
        'extension_runtime_map' => OwnershipScopeRule::SiteGroupOrInstallation,
        'extension_trust_key' => OwnershipScopeRule::SiteGroupOrInstallation,
        'grant' => OwnershipScopeRule::SiteGroupOrInstallation,
        'job' => OwnershipScopeRule::SiteOnly,
        'media' => OwnershipScopeRule::SiteOnly,
        'menu' => OwnershipScopeRule::SiteOnly,
        'menu_item' => OwnershipScopeRule::SiteOnly,
        'organization' => OwnershipScopeRule::SiteOnly,
        'organization_membership' => OwnershipScopeRule::SiteOnly,
        'portal_session' => OwnershipScopeRule::SiteOnly,
        'queue' => OwnershipScopeRule::SiteOnly,
        'resource_policy' => OwnershipScopeRule::SiteOnly,
        'role' => OwnershipScopeRule::SiteGroupOrInstallation,
        'schedule' => OwnershipScopeRule::SiteOnly,
        'separation_duty_rule' => OwnershipScopeRule::SiteOnly,
        'site' => OwnershipScopeRule::SiteOnly,
        'step_up_credential' => OwnershipScopeRule::SiteOnly,
        'theme' => OwnershipScopeRule::SiteGroupOrInstallation,
        'user' => OwnershipScopeRule::SiteGroupOrInstallation,
        'workflow' => OwnershipScopeRule::SiteOnly,
        'workspace' => OwnershipScopeRule::SiteOnly,
    ];

    /**
     * The resource types whose typed policy targets make a capability membership-sensitive.
     *
     * @return  list<string>  The seven types, in the order the table declares them.
     *
     * @since   2.0.0
     */
    public static function membershipResourceTypes(): array
    {
        return self::MEMBERSHIP_RESOURCE_TYPES;
    }

    /**
     * The reserved ownership-rule table, keyed by resource category.
     *
     * @return  array<string, OwnershipScopeRule>  The forty-four categories this build fixes.
     *
     * @since   2.0.0
     */
    public static function reservedOwnershipRules(): array
    {
        return self::RESERVED_OWNERSHIP_RULES;
    }

    /**
     * The membership requirement the package registry is built with.
     *
     * @return  MembershipRequirement  A snapshot of the seven membership-sensitive resource types.
     *
     * @since   2.0.0
     */
    public static function membershipRequirement(): MembershipRequirement
    {
        return new MembershipRequirement(self::MEMBERSHIP_RESOURCE_TYPES);
    }

    /**
     * A fresh ownership-scope policy seeded with the reserved table and no declared categories.
     *
     * The composition root resolves the shared instance through the package factory instead; this is
     * for isolated construction, where a test or a bootstrap needs the same reserved table without a
     * container.
     *
     * @return  ResourceOwnershipScopePolicy  A policy that answers the reserved rules and refuses their redeclaration.
     *
     * @since   2.0.0
     */
    public static function ownershipScopePolicy(): ResourceOwnershipScopePolicy
    {
        return new ResourceOwnershipScopePolicy(self::RESERVED_OWNERSHIP_RULES);
    }
}
